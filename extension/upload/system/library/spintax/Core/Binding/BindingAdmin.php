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
use Spintax\Support\BindingId;

final class BindingAdmin
{
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
        if ('product' !== $entityType) {
            return array(); // category/information (P2), manufacturer (P3)
        }
        $out = array();
        foreach (ProductBinding::COLUMNS as $col) {
            $out[] = array('value' => $col, 'label' => $col . (in_array($col, ProductBinding::REQUIRED_COLUMNS, true) ? ' (required — never cleared)' : ''));
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> all bindings + template name */
    public function all(): array
    {
        return $this->db->query(
            "SELECT b.*, t.name AS template_name FROM `{$this->prefix}spintax_binding` b "
            . "LEFT JOIN `{$this->prefix}spintax_template` t ON b.template_id = t.template_id "
            . "ORDER BY b.date_added"
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function find(string $bindingId): ?array
    {
        $q = $this->db->query(
            "SELECT * FROM `{$this->prefix}spintax_binding` WHERE binding_id = '" . $this->db->escape($bindingId) . "'"
        );
        return $q->num_rows > 0 ? $q->row : null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string> field => error (empty = valid)
     */
    public function validate(array $data): array
    {
        $errors = array();

        if (($data['entity_type'] ?? '') !== 'product') {
            $errors['entity_type'] = 'Only Product is supported in this version.';
        }
        if (($data['target_kind'] ?? '') !== 'description_column') {
            $errors['target_kind'] = 'Only description-column targets are supported in this version.';
        }
        if (!in_array($data['target_column'] ?? '', ProductBinding::COLUMNS, true)) {
            $errors['target_column'] = 'Not a legal target field for this entity.';
        }
        if (($data['source_mode'] ?? '') !== 'template') {
            $errors['source_mode'] = 'Only template source mode is supported in this version.';
        } elseif (!$this->templateExists((int) ($data['template_id'] ?? 0))) {
            $errors['template_id'] = 'Choose an existing template.';
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
            . "target_column = '" . $this->db->escape((string) $data['target_column']) . "', "
            . "source_mode = '" . $this->db->escape((string) $data['source_mode']) . "', "
            . "template_id = " . (int) ($data['template_id'] ?? 0) . ", "
            . "trigger_on_save = " . (int) !empty($data['trigger_on_save']) . ", "
            . "auto_seed_empty = " . (int) !empty($data['auto_seed_empty']) . ", "
            . "regenerate_on_save = " . (int) !empty($data['regenerate_on_save']) . ", "
            . "preserve_manual_edits = " . (int) !empty($data['preserve_manual_edits']) . ", "
            . "clear_on_empty = " . (int) !empty($data['clear_on_empty']) . ", "
            . "status = " . (int) !empty($data['status']) . ", "
            . "date_modified = NOW()";

        try {
            if ($isNew) {
                $this->db->query(
                    "INSERT INTO `{$this->prefix}spintax_binding` SET binding_id = '" . $this->db->escape($bindingId) . "', {$set}, date_added = NOW()"
                );
            } else {
                $this->db->query(
                    "UPDATE `{$this->prefix}spintax_binding` SET {$set} WHERE binding_id = '" . $this->db->escape($bindingId) . "'"
                );
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
        $this->db->query("DELETE FROM `{$this->prefix}spintax_binding` WHERE binding_id = '{$id}'");
        // Purge this binding's walk + signatures (its own bookkeeping only).
        $this->db->query("DELETE FROM `{$this->prefix}spintax_walk` WHERE binding_id = '{$id}'");
        $this->db->query("DELETE FROM `{$this->prefix}spintax_signature` WHERE binding_id = '{$id}'");
    }

    private function templateExists(int $templateId): bool
    {
        if ($templateId <= 0) {
            return false;
        }
        return $this->db->query(
            "SELECT template_id FROM `{$this->prefix}spintax_template` WHERE template_id = " . (int) $templateId
        )->num_rows > 0;
    }
}
