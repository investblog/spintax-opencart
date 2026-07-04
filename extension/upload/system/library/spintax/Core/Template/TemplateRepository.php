<?php
/**
 * Template CRUD over `oc_spintax_template` (spec §10.2 Templates screen).
 *
 * On save of an EXISTING template it runs the §6.3 cache-version cascade: bump
 * `cache_version` on every dependent binding (so the Stale badge lights and the
 * admin is told to Bulk Apply) — it deliberately does NOT rewrite catalog targets.
 * Delete is refused while any binding still references the template.
 *
 * Authoring-time validation and preview are NOT here — they reuse the already
 * tested `Core\Engine\Validator` and the render `Engine` directly.
 *
 * @package Spintax\Core\Template
 */

declare(strict_types=1);

namespace Spintax\Core\Template;

use Spintax\Db\DbInterface;

final class TemplateRepository
{
    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * @return array<int, array<string, mixed>> each with a `used_by` count.
     */
    public function list(): array
    {
        return $this->db->query(
            "SELECT t.*, (SELECT COUNT(*) FROM `{$this->prefix}spintax_binding` b WHERE b.template_id = t.template_id) AS used_by "
            . "FROM `{$this->prefix}spintax_template` t ORDER BY t.name, t.template_id"
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function get(int $templateId): ?array
    {
        $q = $this->db->query(
            "SELECT * FROM `{$this->prefix}spintax_template` WHERE template_id = " . (int) $templateId
        );
        return $q->num_rows > 0 ? $q->row : null;
    }

    /**
     * Insert (id 0) or update a template. Updating runs the §6.3 cascade.
     *
     * @return array{template_id:int, dependents:int}
     */
    public function save(int $templateId, string $name, string $source, string $locale = ''): array
    {
        $n = $this->db->escape($name);
        $s = $this->db->escape($source);
        $l = $this->db->escape($locale);

        // Non-empty names must be unique so `#include "name"` is unambiguous
        // (empty names are allowed — they simply aren't includable).
        if ('' !== trim($name)) {
            $dupe = $this->db->query(
                "SELECT template_id FROM `{$this->prefix}spintax_template` "
                . "WHERE name = '{$n}' AND template_id <> " . (int) $templateId . " LIMIT 1"
            );
            if ($dupe->num_rows > 0) {
                return array('error' => 'A template with this name already exists — names must be unique for #include.');
            }
        }

        if ($templateId > 0) {
            $this->db->query(
                "UPDATE `{$this->prefix}spintax_template` SET name = '{$n}', source = '{$s}', locale = '{$l}', date_modified = NOW() "
                . "WHERE template_id = " . (int) $templateId
            );
            $dependents = $this->cascade($templateId);
            return array('template_id' => $templateId, 'dependents' => $dependents);
        }

        $this->db->query(
            "INSERT INTO `{$this->prefix}spintax_template` SET name = '{$n}', source = '{$s}', locale = '{$l}', date_added = NOW(), date_modified = NOW()"
        );
        $id = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];
        return array('template_id' => $id, 'dependents' => 0);
    }

    /**
     * Delete — refused while any binding references the template.
     *
     * @return array{success:bool}|array{error:string, bindings:string[]}
     */
    public function delete(int $templateId): array
    {
        $deps = $this->dependents($templateId);
        if (!empty($deps)) {
            return array('error' => 'IN_USE', 'bindings' => $deps);
        }
        $this->db->query("DELETE FROM `{$this->prefix}spintax_template` WHERE template_id = " . (int) $templateId);
        return array('success' => true);
    }

    /**
     * Binding ids referencing a template.
     *
     * @return string[]
     */
    public function dependents(int $templateId): array
    {
        $q = $this->db->query(
            "SELECT binding_id FROM `{$this->prefix}spintax_binding` WHERE template_id = " . (int) $templateId
        );
        return array_map(static fn($r): string => (string) $r['binding_id'], $q->rows);
    }

    /**
     * §6.3 cascade: bump cache_version on every dependent binding. Returns the
     * count bumped (for the "N bindings depend on this — run Bulk Apply" notice).
     */
    private function cascade(int $templateId): int
    {
        $deps = $this->dependents($templateId);
        if (!empty($deps)) {
            $this->db->query(
                "UPDATE `{$this->prefix}spintax_binding` SET cache_version = cache_version + 1, date_modified = NOW() "
                . "WHERE template_id = " . (int) $templateId
            );
        }
        return count($deps);
    }
}
