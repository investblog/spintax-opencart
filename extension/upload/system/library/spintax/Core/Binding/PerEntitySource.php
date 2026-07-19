<?php
/**
 * Read/write access to per-entity Spintax sources (`oc_spintax_source`, §4.4) —
 * the `per_entity` source mode's storage. A binding in `per_entity` mode renders
 * an entity's own stored source when present, and falls back to the binding's
 * template when it is missing.
 *
 * Two contracts baked in here (reviewer-agreed):
 *  - **Blank = fallback, not empty.** A blank textarea must mean "use the template",
 *    so {@see save()} DELETES empty/whitespace values instead of storing a
 *    present-but-empty source (which would render empty → SKIP_EMPTY_RENDER).
 *    The resolver ({@see get()}) returns null for a missing row → the caller falls
 *    back to the template.
 *  - **Snapshot invalidation via the existing mechanism.** Any save/delete bumps
 *    `cache_version` + `date_modified` on every `per_entity` binding of that entity
 *    type, so the existing dry-run snapshot token (which fingerprints both) is
 *    rejected — no full-table aggregate in Walk::currentToken().
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Db\DbInterface;
use Spintax\Db\SqlIdentifiers;

final class PerEntitySource
{
    use SqlIdentifiers;

    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /** The per-entity override for one cell, or null when no row exists (→ template fallback). */
    public function get(string $entityType, int $entityId, int $langId): ?string
    {
        $sql = sprintf(
            "SELECT source FROM %s "
            . "WHERE entity_type = '%s' AND entity_id = %d AND language_id = %d",
            $this->table('spintax_source'),
            $this->db->escape($entityType),
            $entityId,
            $langId
        );

        $q = $this->db->query($sql);
        return isset($q->row['source']) ? (string) $q->row['source'] : null;
    }

    /** @return array<int, string> language_id => source (for the form preload). */
    public function loadAll(string $entityType, int $entityId): array
    {
        $sql = sprintf(
            "SELECT language_id, source FROM %s "
            . "WHERE entity_type = '%s' AND entity_id = %d",
            $this->table('spintax_source'),
            $this->db->escape($entityType),
            $entityId
        );

        $q = $this->db->query($sql);
        $out = array();
        foreach ($q->rows as $row) {
            $out[(int) $row['language_id']] = (string) $row['source'];
        }
        return $out;
    }

    /**
     * Persist per-language overrides for one entity. Empty/whitespace values are
     * DELETED (blank = fall back to the template). Bumps affected bindings.
     *
     * @param array<int|string, string> $byLang language_id => source (raw)
     */
    public function save(string $entityType, int $entityId, array $byLang): void
    {
        foreach ($byLang as $langId => $source) {
            $langId = (int) $langId;
            if ('' === trim((string) $source)) {
                $sql = sprintf(
                    "DELETE FROM %s WHERE entity_type = '%s' "
                    . "AND entity_id = %d AND language_id = %d",
                    $this->table('spintax_source'),
                    $this->db->escape($entityType),
                    $entityId,
                    $langId
                );

                $this->db->query($sql);
                continue;
            }
            $sql = sprintf(
                "INSERT INTO %s (entity_type, entity_id, language_id, source, date_added, date_modified) "
                . "VALUES ('%s', %d, %d, '%s', NOW(), NOW()) "
                . "ON DUPLICATE KEY UPDATE source = VALUES(source), date_modified = NOW()",
                $this->table('spintax_source'),
                $this->db->escape($entityType),
                $entityId,
                $langId,
                $this->db->escape((string) $source)
            );

            $this->db->query($sql);
        }
        $this->bump($entityType);
    }

    /** Purge all per-entity sources for an entity (on entity delete) + bump. */
    public function purge(string $entityType, int $entityId): void
    {
        $sql = sprintf(
            "DELETE FROM %s WHERE entity_type = '%s' AND entity_id = %d",
            $this->table('spintax_source'),
            $this->db->escape($entityType),
            $entityId
        );

        $this->db->query($sql);
        $this->bump($entityType);
    }

    /**
     * Invalidate every per_entity binding of this entity type by bumping
     * cache_version + date_modified, so the existing dry-run snapshot token is
     * rejected (§7.1). Mirrors the template-save cascade (§6.3) — one mechanism.
     */
    private function bump(string $entityType): void
    {
        $sql = sprintf(
            "UPDATE %s SET cache_version = cache_version + 1, date_modified = NOW() "
            . "WHERE entity_type = '%s' AND source_mode = 'per_entity'",
            $this->table('spintax_binding'),
            $this->db->escape($entityType)
        );

        $this->db->query($sql);
    }
}
