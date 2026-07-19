<?php
/**
 * Admin-side binding CRUD + the §8.5 reserved-field / legality guards. Used by
 * the binding form endpoints. Kept off the OC runtime so it is DB-testable.
 *
 * Phase 1 scope: entity = product, target_kind = description_column, source_mode
 * = template. Illegal (entity, target) pairs are rejected server-side so the
 * dependent-dropdown client can never force one; physical-cell uniqueness is
 * enforced by the `uniq_binding_target` index (a duplicate is reported cleanly).
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Db\DbInterface;
use Spintax\Db\SqlIdentifiers;
use Spintax\Support\BindingId;

final class BindingAdmin
{
    use SqlIdentifiers;

    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * Legal target fields for the dependent dropdown (§3.1 matrix).
     *
     * @return array<int, array{value:string, label:string}>
     */
    public function legalTargets(string $entityType): array
    {
        $entity = EntityRegistry::get($entityType);
        if (null === $entity) {
            return array(); // unregistered entity (e.g. manufacturer until Phase 3)
        }
        $out = array();
        foreach ($entity->columns as $col) {
            $out[] = array('value' => $col, 'label' => $col . ($entity->isRequiredColumn($col) ? ' (required — never cleared)' : ''));
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> all bindings + template name */
    public function all(): array
    {
        $sql = sprintf(
            "SELECT b.*, t.name AS template_name FROM %s b "
            . "LEFT JOIN %s t ON b.template_id = t.template_id "
            . "ORDER BY b.date_added",
            $this->table('spintax_binding'),
            $this->table('spintax_template')
        );

        return $this->db->query($sql)->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(string $bindingId): ?array
    {
        $sql = sprintf(
            "SELECT * FROM %s WHERE binding_id = '%s'",
            $this->table('spintax_binding'),
            $this->db->escape($bindingId)
        );

        $q = $this->db->query($sql);
        return $q->num_rows > 0 ? $q->row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string> field => error (empty = valid)
     */
    public function validate(array $data): array
    {
        $errors = array();

        $entity = EntityRegistry::get((string) ($data['entity_type'] ?? ''));
        if (null === $entity) {
            $errors['entity_type'] = 'Unsupported entity type.';
        }
        $kind = (string) ($data['target_kind'] ?? '');
        if (!in_array($kind, array('description_column', 'seo_keyword', 'eav_attribute'), true)) {
            $errors['target_kind'] = 'Unsupported target kind.';
        } elseif ('description_column' === $kind && null !== $entity && !$entity->isValidColumn((string) ($data['target_column'] ?? ''))) {
            // seo_keyword has no column (the target is the entity's oc_seo_url keyword).
            $errors['target_column'] = 'Not a legal target field for this entity.';
        } elseif ('eav_attribute' === $kind) {
            // Product custom attributes only, and the attribute must resolve.
            if ('product' !== ($data['entity_type'] ?? '')) {
                $errors['target_kind'] = 'Attribute targets are only available for products.';
            } elseif (!$this->attributeExists((int) ($data['attribute_id'] ?? 0))) {
                $errors['attribute_id'] = 'Choose an existing attribute.';
            }
        }
        if (!in_array($data['source_mode'] ?? '', array('template', 'per_entity'), true)) {
            $errors['source_mode'] = 'Unsupported source mode.';
        } elseif (!$this->templateExists((int) ($data['template_id'] ?? 0))) {
            // Both modes need a template: the source (template mode) or the
            // per-cell fallback used when an entity has no stored source (per_entity).
            $errors['template_id'] = 'Choose an existing template.';
        }
        // per_entity authoring/preload UI (the OCMOD tab) exists only on the product
        // form for now, so restrict the mode to product — a category/information
        // per_entity binding could never be given overrides. Lift when their forms
        // get the tab + preload event.
        if (('per_entity' === ($data['source_mode'] ?? '')) && ('product' !== ($data['entity_type'] ?? ''))) {
            $errors['source_mode'] = 'Per-entity source is only available for products in this version.';
        }

        return $errors;
    }

    /**
     * Insert or update a binding.
     *
     * @param array<string, mixed> $data
     * @return array{binding_id:string}|array{errors:array<string,string>}
     */
    public function save(array $data): array
    {
        $errors = $this->validate($data);
        if (!empty($errors)) {
            return array('errors' => $errors);
        }

        $bindingId = (string) ($data['binding_id'] ?? '');
        $isNew = ('' === $bindingId || null === $this->find($bindingId));
        if ('' === $bindingId || !BindingId::isValid($bindingId)) {
            $bindingId = BindingId::generate();
            $isNew = true;
        }

        $set = "entity_type = '" . $this->db->escape((string) $data['entity_type']) . "', "
            . "target_kind = '" . $this->db->escape((string) $data['target_kind']) . "', "
            . "target_column = '" . $this->db->escape((string) ($data['target_column'] ?? '')) . "', "
            . "attribute_id = " . (int) ($data['attribute_id'] ?? 0) . ", "
            . "source_mode = '" . $this->db->escape((string) $data['source_mode']) . "', "
            . "template_id = " . (int) ($data['template_id'] ?? 0) . ", "
            . "trigger_on_save = " . (int) !empty($data['trigger_on_save']) . ", "
            . "auto_seed_empty = " . (int) !empty($data['auto_seed_empty']) . ", "
            . "regenerate_on_save = " . (int) !empty($data['regenerate_on_save']) . ", "
            . "preserve_manual_edits = " . (int) !empty($data['preserve_manual_edits']) . ", "
            . "clear_on_empty = " . (int) !empty($data['clear_on_empty']) . ", "
            . "seo_disambiguate = " . (int) !empty($data['seo_disambiguate']) . ", "
            . "store_scope = '" . $this->db->escape($this->normalizeStoreScope((string) ($data['store_scope'] ?? 'ALL'))) . "', "
            . "cadence = '" . (in_array($data['cadence'] ?? 'off', array('off', 'auto'), true) ? (string) ($data['cadence'] ?? 'off') : 'off') . "', "
            . "status = " . (int) !empty($data['status']) . ", "
            . "date_modified = NOW()";

        try {
            if ($isNew) {
                $sql = sprintf(
                    "INSERT INTO %s SET binding_id = '%s', %s, date_added = NOW()",
                    $this->table('spintax_binding'),
                    $this->db->escape($bindingId),
                    $set
                );

                $this->db->query($sql);
            } else {
                $sql = sprintf(
                    "UPDATE %s SET %s WHERE binding_id = '%s'",
                    $this->table('spintax_binding'),
                    $set,
                    $this->db->escape($bindingId)
                );

                $this->db->query($sql);
            }
        } catch (\Throwable $e) {
            if (false !== stripos($e->getMessage(), 'uniq_binding_target') || false !== stripos($e->getMessage(), 'Duplicate entry')) {
                return array('errors' => array('target_column' => 'Another binding already targets this field for this scope.'));
            }
            throw $e;
        }

        return array('binding_id' => $bindingId);
    }

    public function delete(string $bindingId): void
    {
        $id = $this->db->escape($bindingId);
        $sql = sprintf("DELETE FROM %s WHERE binding_id = '%s'", $this->table('spintax_binding'), $id);
        $this->db->query($sql);
        // Purge this binding's walk + signatures (its own bookkeeping only).
        $sql = sprintf("DELETE FROM %s WHERE binding_id = '%s'", $this->table('spintax_walk'), $id);
        $this->db->query($sql);
        $sql = sprintf("DELETE FROM %s WHERE binding_id = '%s'", $this->table('spintax_signature'), $id);
        $this->db->query($sql);
    }

    private function attributeExists(int $attributeId): bool
    {
        if ($attributeId <= 0) {
            return false;
        }
        $sql = sprintf(
            "SELECT attribute_id FROM %s WHERE attribute_id = %d",
            $this->table('attribute'),
            $attributeId
        );

        return $this->db->query($sql)->num_rows > 0;
    }

    /** @return array<int, array{value:int, label:string}> product attributes for the eav dropdown. */
    public function attributes(): array
    {
        $sql = sprintf(
            'SELECT a.attribute_id, ad.name FROM %1$s a '
            . 'LEFT JOIN %2$s ad ON a.attribute_id = ad.attribute_id AND ad.language_id = '
            . '(SELECT MIN(language_id) FROM %2$s) '
            . 'ORDER BY ad.name',
            $this->table('attribute'),
            $this->table('attribute_description')
        );

        $rows = $this->db->query($sql)->rows;
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('value' => (int) $r['attribute_id'], 'label' => (string) ($r['name'] ?? ('#' . $r['attribute_id'])));
        }
        return $out;
    }

    /** Normalize store_scope to 'ALL' (default) or a clean CSV of store ids. */
    private function normalizeStoreScope(string $raw): string
    {
        $raw = trim($raw);
        if ('' === $raw || 'ALL' === strtoupper($raw)) {
            return 'ALL';
        }
        $ids = array_filter(array_map('trim', explode(',', $raw)), static fn($v): bool => ctype_digit($v));
        return empty($ids) ? 'ALL' : implode(',', array_map('strval', array_map('intval', $ids)));
    }

    private function templateExists(int $templateId): bool
    {
        if ($templateId <= 0) {
            return false;
        }
        $sql = sprintf(
            "SELECT template_id FROM %s WHERE template_id = %d",
            $this->table('spintax_template'),
            $templateId
        );

        return $this->db->query($sql)->num_rows > 0;
    }
}
