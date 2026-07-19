<?php
/**
 * Activity log (spec §15 Logs page). One row per apply event across the three
 * triggers — save-event, Bulk Apply, cron — so a merchant can see what Spintax
 * actually did. Framework-agnostic (DB-testable). Self-bounding: every ~100th
 * insert prunes back to the newest KEEP rows.
 *
 * @package Spintax\Core\Log
 */

declare(strict_types=1);

namespace Spintax\Core\Log;

use Spintax\Core\Binding\PlanCode;
use Spintax\Db\DbInterface;
use Spintax\Db\SqlIdentifiers;

final class ActivityLog
{
    use SqlIdentifiers;

    /** Rows retained after a prune. */
    public const KEEP = 500;

    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Record one apply event. `origin` ∈ {save, bulk, cron}. A pure no-op
     * (nothing written, skipped or blocked) is not logged, to avoid bloat.
     */
    public function record(string $bindingId, string $origin, ?int $entityId, int $written, int $skipped, int $blocked, string $note = ''): void
    {
        if (0 === $written && 0 === $skipped && 0 === $blocked && '' === $note) {
            return;
        }
        $sql = sprintf(
            "INSERT INTO %s SET "
            . "binding_id = '%s', origin = '%s', entity_id = %d, "
            . "written = %d, skipped = %d, blocked = %d, "
            . "note = '%s', date_added = NOW()",
            $this->table('spintax_log'),
            $this->db->escape($bindingId),
            $this->db->escape($origin),
            $entityId ?? 0,
            $written,
            $skipped,
            $blocked,
            $this->db->escape(mb_substr($note, 0, 255))
        );

        $this->db->query($sql);
        $id = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];
        if (0 === $id % 100) {
            $this->prune();
        }
    }

    /**
     * Tally an applyTo/onEntitySave result array (langId/composite key => code)
     * into written/skipped/blocked and record it in one call.
     *
     * @param array<int|string, string> $codes
     */
    public function recordResult(string $bindingId, string $origin, ?int $entityId, array $codes, string $note = ''): void
    {
        $written = $skipped = $blocked = 0;
        foreach ($codes as $code) {
            switch (PlanCode::category((string) $code)) {
                case 'write':
                    ++$written;
                    break;
                case 'blocked':
                    ++$blocked;
                    break;
                default:
                    ++$skipped;
            }
        }
        $this->record($bindingId, $origin, $entityId, $written, $skipped, $blocked, $note);
    }

    /**
     * @return array<int, array<string, mixed>> newest-first recent rows.
     */
    public function recent(int $limit = 100): array
    {
        $sql = sprintf(
            'SELECT * FROM %s ORDER BY log_id DESC LIMIT %d',
            $this->table('spintax_log'),
            max(1, $limit)
        );

        return $this->db->query($sql)->rows;
    }

    /** Trim to the newest KEEP rows. */
    public function prune(int $keep = self::KEEP): void
    {
        $sql = sprintf(
            'SELECT log_id FROM %s ORDER BY log_id DESC LIMIT 1 OFFSET %d',
            $this->table('spintax_log'),
            max(0, $keep)
        );

        $row = $this->db->query($sql)->row;
        if (!empty($row)) {
            $sql = sprintf(
                'DELETE FROM %s WHERE log_id <= %d',
                $this->table('spintax_log'),
                $row['log_id']
            );

            $this->db->query($sql);
        }
    }
}
