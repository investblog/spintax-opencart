<?php
/**
 * Loads binding configs for the save-event runner (spec §6.1). MVP scope:
 * enabled Product × description_column bindings in `template` source mode; the
 * template source is resolved via a LEFT JOIN. per_entity mode + other entities
 * arrive in later phases.
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
     * Enabled Product description_column bindings, each paired with its resolved
     * source (null when the template record is missing → SKIP_SOURCE_NOT_FOUND).
     *
     * @return array<int, array{binding: ProductBinding, source: ?string}>
     */
    public function enabledProductBindings(): array
    {
        $q = $this->db->query(
            "SELECT b.binding_id, b.target_column, b.auto_seed_empty, b.regenerate_on_save, "
            . "b.preserve_manual_edits, b.clear_on_empty, b.source_mode, b.template_id, t.source AS template_source "
            . "FROM `{$this->prefix}spintax_binding` b "
            . "LEFT JOIN `{$this->prefix}spintax_template` t ON b.template_id = t.template_id "
            // trigger_on_save = 1: the save event only fires for bindings that opted
            // into it. The zero-config demo is enabled but trigger_on_save = 0, so a
            // product save never auto-writes; it is reached only via Bulk Apply.
            . "WHERE b.entity_type = 'product' AND b.target_kind = 'description_column' "
            . "AND b.status = '1' AND b.trigger_on_save = '1'"
        );

        $out = array();
        foreach ($q->rows as $row) {
            $binding = new ProductBinding(
                (string) $row['binding_id'],
                (string) $row['target_column'],
                (bool) (int) $row['auto_seed_empty'],
                (bool) (int) $row['regenerate_on_save'],
                (bool) (int) $row['preserve_manual_edits'],
                (bool) (int) $row['clear_on_empty']
            );

            // template mode: source resolves via the join (null if record missing).
            // per_entity mode is out of MVP scope → treated as unresolved for now.
            $source = ('template' === $row['source_mode']) ? ($row['template_source'] ?? null) : null;
            if (null !== $source) {
                $source = (string) $source;
            }

            $out[] = array('binding' => $binding, 'source' => $source);
        }

        return $out;
    }
}
