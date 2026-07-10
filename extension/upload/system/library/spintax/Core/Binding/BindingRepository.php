<?php
/**
 * Loads binding configs for the save-event runner (spec §6.1). Returns enabled,
 * trigger-on-save `description_column` bindings for a given entity type, each
 * paired with its resolved template source (a LEFT JOIN). per_entity source mode
 * arrives in a later phase (treated as unresolved for now).
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Db\DbInterface;

final class BindingRepository
{
    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Enabled description_column bindings for one entity type that opted into the
     * save event, each paired with its resolved source (null when the template
     * record is missing → SKIP_SOURCE_NOT_FOUND).
     *
     * trigger_on_save = 1: the save event only fires for bindings that opted in.
     * The zero-config demo is enabled but trigger_on_save = 0, so a save never
     * auto-writes; it is reached only via Bulk Apply.
     *
     * @return array<int, array{binding: EntityBinding, source: ?string}>
     */
    public function enabledBindingsFor(string $entityType): array
    {
        $q = $this->db->query(
            "SELECT b.binding_id, b.entity_type, b.target_kind, b.target_column, b.attribute_id, "
            . "b.auto_seed_empty, b.regenerate_on_save, b.preserve_manual_edits, b.clear_on_empty, "
            . "b.source_mode, b.template_id, b.seo_disambiguate, b.store_scope, t.source AS template_source "
            . "FROM `" . $this->prefix . "spintax_binding` b "
            . "LEFT JOIN `" . $this->prefix . "spintax_template` t ON b.template_id = t.template_id "
            . "WHERE b.entity_type = '" . $this->db->escape($entityType) . "' "
            . "AND b.target_kind IN ('description_column', 'seo_keyword', 'eav_attribute') "
            . "AND b.status = '1' AND b.trigger_on_save = '1'"
        );

        $out = array();
        foreach ($q->rows as $row) {
            // Skip any row whose entity_type is not registered (defensive).
            if (null === EntityRegistry::get((string) $row['entity_type'])) {
                continue;
            }
            $binding = EntityBinding::fromRow($row);

            // Both template and per_entity resolve the binding's template via the
            // join (null if the record is missing). For per_entity it is the
            // per-cell FALLBACK the Applier uses when the entity has no stored source.
            $source = isset($row['template_source']) ? (string) $row['template_source'] : null;

            $out[] = array('binding' => $binding, 'source' => $source);
        }

        return $out;
    }
}
