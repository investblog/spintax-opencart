<?php
/**
 * The bulk walk (spec §7). Two operations over a binding's whole scope, both
 * driven by the SAME Applier/Planner as the save event:
 *
 *   dryRun()    — count total/write/skip/blocked over the scope WITHOUT writing;
 *                 store a snapshot + `snapshot_token` on the walk row (§7.1).
 *   applyChunk() — validate the token (STALE_SNAPSHOT on drift), take the walk
 *                 lock, process one LIMIT/OFFSET chunk of entities, and STOP on
 *                 the first write error (not best-effort, §7.4).
 *
 * Cursor + progress are counted in ENTITIES; write/skip/blocked counts are in
 * CELLS (entity × language). Scope: the binding's entity × description_column,
 * all active languages, default store.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Catalog\LanguageResolver;
use Spintax\Db\DbInterface;

final class Walk
{
    private const STALE_LOCK_SECONDS = 3600;

    private DbInterface $db;
    private string $prefix;
    private Applier $applier;
    private LanguageResolver $langs;

    public function __construct(DbInterface $db, string $prefix, Applier $applier, LanguageResolver $langs)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->applier = $applier;
        $this->langs = $langs;
    }

    /**
     * Current snapshot token for a binding row (recomputed from live state).
     *
     * @param array<string, mixed> $bindingRow
     */
    public function currentToken(array $bindingRow): string
    {
        $templateModified = '';
        if ('template' === ($bindingRow['source_mode'] ?? '') && (int) ($bindingRow['template_id'] ?? 0) > 0) {
            $t = $this->db->query(
                "SELECT date_modified FROM `" . $this->prefix . "spintax_template` WHERE template_id = " . (int) $bindingRow['template_id']
            );
            $templateModified = (string) ($t->row['date_modified'] ?? '');
        }

        return DryRunToken::compute(
            (string) $bindingRow['binding_id'],
            (string) ($bindingRow['date_modified'] ?? ''),
            (int) ($bindingRow['template_id'] ?? 0),
            $templateModified,
            (int) ($bindingRow['cache_version'] ?? 1),
            array_keys($this->langs->activeLanguages()),
            (string) ($bindingRow['store_scope'] ?? 'ALL')
        );
    }

    /**
     * Dry run over the whole scope — no writes. Resets the walk row and stores
     * the snapshot token.
     *
     * @param array<string, mixed> $bindingRow
     * @return array{dry_run_token:string, entities:int, total:int, write:int, skip:int, blocked:int, breakdown:array<string,int>}
     */
    public function dryRun(array $bindingRow, ?string $source): array
    {
        if (1 !== (int) ($bindingRow['status'] ?? 0)) {
            return array('error' => 'BINDING_DISABLED', 'message' => 'Enable the binding before running it.');
        }

        $binding = EntityBinding::fromRow($bindingRow);
        $token = $this->currentToken($bindingRow);

        $total = 0;
        $counts = array('write' => 0, 'skip' => 0, 'blocked' => 0);
        $breakdown = array();

        $offset = 0;
        $scan = 200;
        while (true) {
            $ids = $this->entityIds($binding->entity, $offset, $scan);
            if (empty($ids)) {
                break;
            }
            foreach ($ids as $pid) {
                foreach ($this->applier->planEntity($pid, $binding, $source) as $cell) {
                    ++$total;
                    ++$counts[PlanCode::category($cell['code'])];
                    $breakdown[$cell['code']] = ($breakdown[$cell['code']] ?? 0) + 1;
                }
            }
            $offset += $scan;
        }

        $entityTotal = $this->totalEntities($binding->entity);
        $this->resetWalk((string) $bindingRow['binding_id'], $entityTotal, (int) ($bindingRow['cache_version'] ?? 1), $token);

        return array(
            'dry_run_token' => $token,
            'entities' => $entityTotal,
            'total' => $total,
            'write' => $counts['write'],
            'skip' => $counts['skip'],
            'blocked' => $counts['blocked'],
            'breakdown' => $breakdown,
        );
    }

    /**
     * Apply one chunk from the walk cursor. Requires the dry-run token.
     *
     * @param array<string, mixed> $bindingRow
     * @return array<string, mixed> progress, or {error:...}
     */
    public function applyChunk(array $bindingRow, ?string $source, string $token, ?int $chunkSize = null, ?int $lockTs = null): array
    {
        if (1 !== (int) ($bindingRow['status'] ?? 0)) {
            return array('error' => 'BINDING_DISABLED', 'message' => 'Enable the binding before running it.');
        }

        $bindingId = (string) $bindingRow['binding_id'];
        $walk = $this->loadWalk($bindingId);
        if (null === $walk) {
            return array('error' => 'NO_DRY_RUN', 'message' => 'Run a Dry run before Apply.');
        }

        // Snapshot-token check: recomputed AND stored must both match. Any binding
        // /template/active-language change since the dry run → reject (§7.1).
        if ($token !== $this->currentToken($bindingRow) || $token !== $walk['snapshot_token']) {
            return array('error' => 'STALE_SNAPSHOT', 'message' => 'Config changed since the Dry run — re-run Dry run.');
        }

        // Walk lock (§7.3). CAS-acquire when free/stale; a live lock only lets
        // THIS session's chunks through (client echoes the lock_ts it received);
        // any other Apply while the lock is fresh is refused. The refusal
        // deliberately does NOT leak lock_ts, so a refused caller can't masquerade.
        $now = time();
        $ownedLockTs = $this->acquireOrContinueLock($bindingId, $now, $lockTs, (int) $walk['lock_ts']);
        if (null === $ownedLockTs) {
            return array('error' => 'WALK_LOCKED', 'message' => 'Another Apply is already running for this binding.');
        }

        $binding = EntityBinding::fromRow($bindingRow);
        $chunk = $chunkSize ?? ((int) ($bindingRow['chunk_size'] ?? 0) ?: 20);
        $ids = $this->entityIds($binding->entity, (int) $walk['cursor_offset'], $chunk);

        $written = 0;
        $skipped = 0;
        $blocked = 0;
        $failed = 0;
        $lastId = 0;

        foreach ($ids as $pid) {
            try {
                foreach ($this->applier->applyTo($pid, $binding, $source) as $code) {
                    switch (PlanCode::category($code)) {
                        case 'write':
                            ++$written;
                            break;
                        case 'blocked':
                            ++$blocked; // SEO collision / missing source / forbidden clear — surfaced in logs
                            break;
                        default:
                            ++$skipped;
                    }
                }
                $lastId = $pid;
            } catch (\Throwable $e) {
                // WRITE ERROR → hard stop, flag the walk, DO NOT stamp the version.
                ++$failed;
                $this->markFailed($bindingId);
                return array(
                    'error' => 'WRITE_FAILED',
                    'message' => $e->getMessage(),
                    'last_id' => $pid,
                    'written' => $written,
                    'skipped' => $skipped,
                    'blocked' => $blocked,
                    'failed' => $failed,
                    'done' => false,
                );
            }
        }

        $newCursor = (int) $walk['cursor_offset'] + $chunk;
        $total = (int) $walk['total'];
        $done = $newCursor >= $total;
        $processed = min($newCursor, $total);

        $this->advance($bindingId, $newCursor, $processed, $done, (int) $walk['cache_version']);

        return array(
            'processed' => $processed,
            'entities_total' => $total,
            'written' => $written,
            'skipped' => $skipped,
            'blocked' => $blocked,
            'failed' => $failed,
            'last_id' => $lastId,
            'cursor' => $newCursor,
            'done' => $done,
            // The client echoes lock_ts on the next chunk to prove it owns the walk.
            'lock_ts' => $done ? 0 : $ownedLockTs,
        );
    }

    /**
     * Manual "Force release" — only clears a STALE lock (crashed worker). Refuses
     * to yank a live lock out from under a running walk (that would let a second
     * walk start and double-advance the cursor).
     *
     * @return array{success:bool}|array{error:string}
     */
    public function releaseLock(string $bindingId): array
    {
        $now = time();
        $this->db->query(
            "UPDATE `" . $this->prefix . "spintax_walk` SET lock_ts = 0, date_modified = NOW() "
            . "WHERE binding_id = '" . $this->db->escape($bindingId) . "' "
            . "AND (lock_ts = 0 OR lock_ts < " . ($now - self::STALE_LOCK_SECONDS) . ")"
        );
        if ($this->db->affectedRows() < 1) {
            // Either no such row, or the lock is live → refuse.
            $walk = $this->loadWalk($bindingId);
            if (null !== $walk && (int) $walk['lock_ts'] >= $now - self::STALE_LOCK_SECONDS && (int) $walk['lock_ts'] !== 0) {
                return array('error' => 'LOCK_ACTIVE', 'message' => 'A walk is currently running — cannot force-release a live lock.');
            }
        }
        return array('success' => true);
    }

    /**
     * Release a lock the CURRENT worker owns when pausing an unfinished walk (the
     * cron stopping at its chunk/time budget), so the next tick can re-acquire and
     * continue. CAS on the exact lock_ts we hold — never yanks another worker's lock.
     */
    public function pauseLock(string $bindingId, int $lockTs): void
    {
        $this->db->query(
            "UPDATE `" . $this->prefix . "spintax_walk` SET lock_ts = 0, date_modified = NOW() "
            . "WHERE binding_id = '" . $this->db->escape($bindingId) . "' AND lock_ts = " . (int) $lockTs
        );
    }

    /** @return array<string, mixed>|null */
    public function loadWalk(string $bindingId): ?array
    {
        $q = $this->db->query(
            "SELECT * FROM `" . $this->prefix . "spintax_walk` WHERE binding_id = '" . $this->db->escape($bindingId) . "'"
        );
        return $q->num_rows > 0 ? $q->row : null;
    }

    // --- internals -----------------------------------------------------------

    /** @return int[] */
    private function entityIds(EntityType $entity, int $offset, int $limit): array
    {
        $q = $this->db->query(
            "SELECT `" . $entity->idColumn . "` AS id FROM `" . $this->prefix . $entity->baseTable . "` "
            . "ORDER BY `" . $entity->idColumn . "` LIMIT " . (int) $limit . " OFFSET " . (int) $offset
        );
        return array_map(static fn($r): int => (int) $r['id'], $q->rows);
    }

    private function totalEntities(EntityType $entity): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS c FROM `" . $this->prefix . $entity->baseTable . "`")->row['c'];
    }

    private function resetWalk(string $bindingId, int $total, int $cacheVersion, string $token): void
    {
        // INSERT ... ON DUPLICATE KEY UPDATE preserves last_applied_version and
        // last_run (Stale-badge + cadence state) while resetting cursor/counts.
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "spintax_walk` "
            . "(binding_id, cursor_offset, total, processed, lock_ts, walk_failed, cache_version, last_applied_version, last_run, snapshot_token, date_modified) "
            . "VALUES ('" . $this->db->escape($bindingId) . "', 0, " . (int) $total . ", 0, 0, 0, " . (int) $cacheVersion . ", 0, 0, '" . $this->db->escape($token) . "', NOW()) "
            . "ON DUPLICATE KEY UPDATE cursor_offset = 0, total = VALUES(total), processed = 0, lock_ts = 0, "
            . "walk_failed = 0, cache_version = VALUES(cache_version), snapshot_token = VALUES(snapshot_token), date_modified = NOW()"
        );
    }

    /**
     * CAS lock acquisition / continuation. Returns the lock_ts the caller now
     * owns, or null when the lock is held by another live walk.
     *
     * - free/stale lock → acquire (conditional UPDATE; the WHERE clause makes it
     *   atomic even for two racing acquires — only one row matches);
     * - live lock + caller echoes the matching lock_ts → refresh (continuation);
     * - live lock + wrong/absent lock_ts → refuse (null).
     */
    private function acquireOrContinueLock(string $bindingId, int $now, ?int $lockTs, int $currentLock): ?int
    {
        $stale = $now - self::STALE_LOCK_SECONDS;
        $live = (0 !== $currentLock && $currentLock >= $stale);

        if ($live) {
            // Live lock: only the owner (echoing the exact lock_ts we handed out)
            // may continue. Ownership is decided from the already-loaded row, NOT
            // from affected_rows — a same-second refresh writes an identical value,
            // which MySQL reports as 0 affected rows.
            if (null !== $lockTs && (int) $lockTs === $currentLock) {
                $this->db->query(
                    "UPDATE `" . $this->prefix . "spintax_walk` SET lock_ts = " . (int) $now . ", date_modified = NOW() WHERE binding_id = '" . $this->db->escape($bindingId) . "'"
                );
                return $now;
            }
            return null; // held by another live walk
        }

        // Free or stale → CAS acquire (0/stale → now is a real value change, so
        // affected_rows is reliable; two racing acquires: only one matches).
        $this->db->query(
            "UPDATE `" . $this->prefix . "spintax_walk` SET lock_ts = " . (int) $now . ", date_modified = NOW() "
            . "WHERE binding_id = '" . $this->db->escape($bindingId) . "' AND (lock_ts = 0 OR lock_ts < " . (int) $stale . ")"
        );
        return $this->db->affectedRows() >= 1 ? $now : null;
    }

    private function markFailed(string $bindingId): void
    {
        $this->db->query(
            "UPDATE `" . $this->prefix . "spintax_walk` SET walk_failed = 1, lock_ts = 0, date_modified = NOW() WHERE binding_id = '" . $this->db->escape($bindingId) . "'"
        );
    }

    private function advance(string $bindingId, int $cursor, int $processed, bool $done, int $cacheVersion): void
    {
        $sql = "UPDATE `" . $this->prefix . "spintax_walk` SET cursor_offset = " . (int) $cursor . ", processed = " . (int) $processed . ", ";
        if ($done) {
            // Zero-failure completion stamps the applied version + releases the lock (§7.4).
            $sql .= "last_applied_version = IF(walk_failed = 0, " . (int) $cacheVersion . ", last_applied_version), lock_ts = 0, ";
        }
        $sql .= "date_modified = NOW() WHERE binding_id = '" . $this->db->escape($bindingId) . "'";
        $this->db->query($sql);
    }
}
