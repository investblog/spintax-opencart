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

final class ActivityLog
{
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
        $this->db->query(
            "INSERT INTO `" . $this->prefix . "spintax_log` SET "
            . "binding_id = '" . $this->db->escape($bindingId) . "', "
            . "origin = '" . $this->db->escape($origin) . "', "
            . "entity_id = " . (int) ($entityId ?? 0) . ", "
            . "written = " . (int) $written . ", skipped = " . (int) $skipped . ", blocked = " . (int) $blocked . ", "
            . "note = '" . $this->db->escape(mb_substr($note, 0, 255)) . "', date_added = NOW()"
        );
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
        return $this->db->query(
            "SELECT * FROM `" . $this->prefix . "spintax_log` ORDER BY log_id DESC LIMIT " . max(1, (int) $limit)
        )->rows;
    }

    /** Trim to the newest KEEP rows. */
    public function prune(int $keep = self::KEEP): void
    {
        $row = $this->db->query(
            "SELECT log_id FROM `" . $this->prefix . "spintax_log` ORDER BY log_id DESC LIMIT 1 OFFSET " . max(0, (int) $keep)
        )->row;
        if (!empty($row)) {
            $this->db->query("DELETE FROM `" . $this->prefix . "spintax_log` WHERE log_id <= " . (int) $row['log_id']);
        }
    }
}
