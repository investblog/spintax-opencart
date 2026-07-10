<?php
/**
 * Self-scheduled cron tick (spec §6, §16 item 18). OpenCart 3.0.x core has NO
 * `cron/cron` route and NO oc_cron table, so we ship our own **tokenized storefront
 * route** (see catalog controller) that a system/web cron hits on a schedule; this
 * class is the framework-agnostic body it calls, so it is DB-testable off the OC
 * runtime.
 *
 * Each tick:
 *   1. self-schedule — skip unless `interval` seconds have elapsed since `last_run`
 *      (stored in oc_setting); the very act of reading+writing last_run serialises
 *      overlapping cron hits;
 *   2. claim the tick (write last_run = now);
 *   3. pick DUE bindings — enabled bindings whose walk is stale (a template/config
 *      change bumped cache_version past last_applied_version), unfinished, or failed;
 *   4. advance each via the SAME Walk machinery as Bulk Apply, honouring the walk
 *      lock (an in-flight admin Apply is left alone — WALK_LOCKED is not an error).
 *      An unfinished walk with a still-valid snapshot is CONTINUED (not reset), so
 *      a big catalog makes progress across ticks instead of restarting each time.
 *
 * @package Spintax\Core\Cron
 */

declare(strict_types=1);

namespace Spintax\Core\Cron;

use Spintax\Core\Binding\Walk;
use Spintax\Core\Log\ActivityLog;
use Spintax\Db\DbInterface;

final class CronRunner
{
    private DbInterface $db;
    private string $prefix;
    private Walk $walk;
    private ?ActivityLog $log;

    public function __construct(DbInterface $db, string $prefix, Walk $walk, ?ActivityLog $log = null)
    {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->walk = $walk;
        $this->log = $log;
    }

    /**
     * Run one cron tick.
     *
     * @param int $now          current unix time (injected for testability)
     * @param int $interval     minimum seconds between runs
     * @param int $maxBindings  cap on bindings processed this tick
     * @param int $maxChunks    cap on walk chunks per binding this tick
     * @return array<string, mixed>
     */
    public function run(int $now, int $interval, int $maxBindings = 5, int $maxChunks = 50, int $maxSeconds = 20): array
    {
        $lastRun = $this->lastRun();
        if ($lastRun > 0 && ($now - $lastRun) < $interval) {
            return array('status' => 'not_due', 'last_run' => $lastRun, 'wait' => $interval - ($now - $lastRun));
        }

        // Claim the tick by advancing last_run first. NOTE: this alone does NOT
        // serialise two truly-concurrent hits (the read+write is not atomic and
        // oc_setting has no unique key) — the per-binding walk-lock CAS is the real
        // backstop (a losing concurrent Apply gets WALK_LOCKED). This just keeps
        // ordinary frequent hits cheap.
        $this->setLastRun($now);

        $deadline = time() + max(1, $maxSeconds);
        $processed = array();
        foreach ($this->dueBindings($maxBindings) as $row) {
            if (time() >= $deadline) {
                break;
            }
            $processed[] = $this->runBinding($row, $maxChunks, $deadline);
        }

        return array('status' => 'ran', 'last_run' => $now, 'count' => count($processed), 'bindings' => $processed);
    }

    /**
     * Bindings the cron may run: enabled, OPTED IN to cron (cadence != 'off' — so a
     * merchant configuring the cron URL can never auto-publish a binding they didn't
     * schedule, incl. the safe-by-default demo binding), and needing work (no walk
     * yet / stale version drift / unfinished). A FAILED walk is backed off for an
     * hour rather than retried every tick.
     */
    private function dueBindings(int $limit): array
    {
        return $this->db->query(
            "SELECT b.* FROM `" . $this->prefix . "spintax_binding` b "
            . "LEFT JOIN `" . $this->prefix . "spintax_walk` w ON b.binding_id = w.binding_id "
            . "WHERE b.status = 1 AND b.cadence <> 'off' AND ("
            . "w.binding_id IS NULL "
            . "OR w.last_applied_version < b.cache_version OR w.processed < w.total "
            . "OR (w.walk_failed = 1 AND w.date_modified < (NOW() - INTERVAL 1 HOUR))) "
            . "ORDER BY b.date_modified LIMIT " . (int) $limit
        )->rows;
    }

    /** @param array<string, mixed> $bindingRow */
    private function runBinding(array $bindingRow, int $maxChunks, int $deadline): array
    {
        $bindingId = (string) $bindingRow['binding_id'];
        $source = $this->sourceFor($bindingRow);

        // Continue an in-progress walk whose snapshot is still valid; else start a
        // fresh dry-run (which resets the cursor + mints a token).
        $existing = $this->walk->loadWalk($bindingId);
        $currentToken = $this->walk->currentToken($bindingRow);
        if (null !== $existing && $existing['snapshot_token'] === $currentToken && (int) $existing['processed'] < (int) $existing['total']) {
            $token = $currentToken;
        } else {
            $dry = $this->walk->dryRun($bindingRow, $source);
            if (isset($dry['error'])) {
                return array('binding_id' => $bindingId, 'error' => $dry['error']);
            }
            $token = (string) $dry['dry_run_token'];
        }

        $written = 0;
        $skipped = 0;
        $blocked = 0;
        $lockTs = null;
        $done = false;
        for ($i = 0; $i < $maxChunks && !$done; ++$i) {
            if (time() >= $deadline) {
                break; // wall-clock budget — reach a chunk boundary before a PHP timeout
            }
            $r = $this->walk->applyChunk($bindingRow, $source, $token, null, $lockTs);
            if (isset($r['error'])) {
                // WALK_LOCKED = an admin Apply owns it → leave it, not an error state.
                return array('binding_id' => $bindingId, 'result' => $r['error'], 'written' => $written);
            }
            $written += (int) ($r['written'] ?? 0);
            $skipped += (int) ($r['skipped'] ?? 0);
            $blocked += (int) ($r['blocked'] ?? 0);
            $lockTs = isset($r['lock_ts']) ? (int) $r['lock_ts'] : null;
            $done = (bool) ($r['done'] ?? false);
        }

        // Paused before finishing while holding the lock → release it (CAS on the
        // ts we own) so the NEXT tick can re-acquire and continue. Without this the
        // lock stays live and every following tick is a WALK_LOCKED no-op until it
        // goes stale (~1h), stalling any catalog bigger than maxChunks × chunk.
        if (!$done && null !== $lockTs && $lockTs > 0) {
            $this->walk->pauseLock($bindingId, $lockTs);
        }

        $this->log?->record($bindingId, 'cron', null, $written, $skipped, $blocked, $done ? '' : 'paused (resumes next tick)');

        return array('binding_id' => $bindingId, 'written' => $written, 'skipped' => $skipped, 'blocked' => $blocked, 'done' => $done);
    }

    /** Resolve the binding's template source (the per_entity fallback too). */
    private function sourceFor(array $bindingRow): ?string
    {
        if ((int) ($bindingRow['template_id'] ?? 0) > 0) {
            $q = $this->db->query(
                "SELECT source FROM `" . $this->prefix . "spintax_template` WHERE template_id = " . (int) $bindingRow['template_id']
            );
            return $q->num_rows > 0 ? (string) $q->row['source'] : null;
        }
        return null;
    }

    private function lastRun(): int
    {
        $q = $this->db->query(
            "SELECT `value` FROM `" . $this->prefix . "setting` "
            . "WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_last_run' AND store_id = 0"
        );
        return (int) ($q->row['value'] ?? 0);
    }

    private function setLastRun(int $ts): void
    {
        // The row is seeded at install, so a plain UPDATE is enough (no delete-then-
        // insert, so no duplicate rows under concurrency). If it is somehow missing,
        // create it once.
        $this->db->query(
            "UPDATE `" . $this->prefix . "setting` SET `value` = '" . (int) $ts . "' "
            . "WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_last_run' AND store_id = 0"
        );
        if ($this->db->affectedRows() < 1 && 0 === $this->lastRun()) {
            $this->db->query(
                "INSERT INTO `" . $this->prefix . "setting` SET store_id = 0, `code` = 'spintax_seo', `key` = 'spintax_seo_last_run', `value` = '" . (int) $ts . "', serialized = '0'"
            );
        }
    }
}
