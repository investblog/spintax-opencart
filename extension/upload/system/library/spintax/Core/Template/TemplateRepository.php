<?php
/**
 * Template CRUD over `oc_spintax_template` (spec §10.2 Templates screen).
 *
 * On save of an EXISTING template it runs the §6.3 cache-version cascade: bump
 * `cache_version` on every dependent binding (so the Stale badge lights, the dry-run
 * token invalidates, and cron re-applies) — it deliberately does NOT rewrite catalog
 * targets. "Dependent" follows the `#include` graph: a binding is dependent if its
 * template includes the edited one, directly or transitively (§9.3). Delete is
 * refused while any binding depends on the template OR any other template includes it.
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
            "SELECT t.*, (SELECT COUNT(*) FROM `" . $this->prefix . "spintax_binding` b WHERE b.template_id = t.template_id) AS used_by "
            . "FROM `" . $this->prefix . "spintax_template` t ORDER BY t.name, t.template_id"
        )->rows;
    }

    /** @return array<string, mixed>|null */
    public function get(int $templateId): ?array
    {
        $q = $this->db->query(
            "SELECT * FROM `" . $this->prefix . "spintax_template` WHERE template_id = " . (int) $templateId
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
        // Non-empty names must be unique so `#include "name"` is unambiguous
        // (empty names are allowed — they simply aren't includable).
        if ('' !== trim($name)) {
            $dupe = $this->db->query(
                "SELECT template_id FROM `" . $this->prefix . "spintax_template` "
                . "WHERE name = '" . $this->db->escape($name) . "' AND template_id <> " . (int) $templateId . " LIMIT 1"
            );
            if ($dupe->num_rows > 0) {
                return array('error' => 'A template with this name already exists — names must be unique for #include.');
            }
        }

        if ($templateId > 0) {
            // Union the dependents of the OLD name/source (before the write) with the
            // NEW ones (after) — so a RENAME that orphans an `#include` of the old name
            // still invalidates the template that pulled it in (§9.3), not only edits.
            $before = $this->affectedTemplateIds($templateId);
            $this->db->query(
                "UPDATE `" . $this->prefix . "spintax_template` SET name = '" . $this->db->escape($name) . "', source = '" . $this->db->escape($source) . "', locale = '" . $this->db->escape($locale) . "', date_modified = NOW() "
                . "WHERE template_id = " . (int) $templateId
            );
            $after = $this->affectedTemplateIds($templateId);
            $dependents = $this->bumpBindings(array_values(array_unique(array_merge($before, $after))));
            return array('template_id' => $templateId, 'dependents' => $dependents);
        }

        $this->db->query(
            "INSERT INTO `" . $this->prefix . "spintax_template` SET name = '" . $this->db->escape($name) . "', source = '" . $this->db->escape($source) . "', locale = '" . $this->db->escape($locale) . "', date_added = NOW(), date_modified = NOW()"
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
        // Refuse if any binding depends on it (directly or through an #include
        // chain), OR if any other template #includes it — deleting a shared partial
        // would silently break every template that pulls it in (§9.3).
        $deps = $this->dependents($templateId);
        $includedBy = $this->includerNames($templateId);
        if (!empty($deps) || !empty($includedBy)) {
            return array('error' => 'IN_USE', 'bindings' => $deps, 'included_by' => $includedBy);
        }
        $this->db->query("DELETE FROM `" . $this->prefix . "spintax_template` WHERE template_id = " . (int) $templateId);
        return array('success' => true);
    }

    /**
     * Binding ids whose rendered output depends on this template — those bound to
     * it directly AND those bound to a template that (transitively) `#include`s it.
     *
     * @return string[]
     */
    public function dependents(int $templateId): array
    {
        return $this->bindingsFor($this->affectedTemplateIds($templateId));
    }

    /** Binding ids bound to any of the given template ids. @return string[] */
    private function bindingsFor(array $templateIds): array
    {
        $inList = implode(',', array_map('intval', $templateIds));
        if ('' === $inList) {
            return array();
        }
        $q = $this->db->query(
            "SELECT binding_id FROM `" . $this->prefix . "spintax_binding` WHERE template_id IN (" . $inList . ")"
        );
        return array_map(static fn($r): string => (string) $r['binding_id'], $q->rows);
    }

    /**
     * §6.3 cascade: bump cache_version on bindings bound to any of the given template
     * ids, so the Stale badge lights, the dry-run token invalidates, and cron
     * re-applies. Returns the count bumped.
     */
    private function bumpBindings(array $templateIds): int
    {
        $deps = $this->bindingsFor($templateIds);
        if (!empty($deps)) {
            $inList = implode(',', array_map('intval', $templateIds));
            $this->db->query(
                "UPDATE `" . $this->prefix . "spintax_binding` SET cache_version = cache_version + 1, date_modified = NOW() "
                . "WHERE template_id IN (" . $inList . ")"
            );
        }
        return count($deps);
    }

    /**
     * Template ids whose render output depends on $changedId: the template itself
     * plus every template that transitively `#include`s it. Each `#include` name is
     * resolved to a template id the SAME way the render resolver does (SQL
     * `WHERE name = …`), so the cascade can never diverge from what render actually
     * splices in (e.g. an accent-/case-folding collation match).
     *
     * @return int[]
     */
    private function affectedTemplateIds(int $changedId): array
    {
        // id => [template ids it directly #includes], edges via render's name lookup.
        $edges = array();
        $resolved = array(); // memoize name → id
        foreach ($this->db->query("SELECT template_id, source FROM `" . $this->prefix . "spintax_template`")->rows as $t) {
            $ids = array();
            foreach ($this->includeNames((string) $t['source']) as $name) {
                if (!array_key_exists($name, $resolved)) {
                    $resolved[$name] = $this->templateIdByName($name);
                }
                if (null !== $resolved[$name]) {
                    $ids[] = $resolved[$name];
                }
            }
            $edges[(int) $t['template_id']] = $ids;
        }

        // Reverse BFS to a fixpoint: a template is affected if it includes an already
        // affected id. Monotonic + bounded by the template count, so it terminates
        // even with include cycles.
        $affected = array($changedId => true);
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($edges as $id => $includes) {
                if (isset($affected[$id])) {
                    continue;
                }
                foreach ($includes as $inc) {
                    if (isset($affected[$inc])) {
                        $affected[$id] = true;
                        $changed = true;
                        break;
                    }
                }
            }
        }
        return array_keys($affected);
    }

    /** Names of OTHER templates whose `#include` resolves to $templateId (delete guard). */
    private function includerNames(int $templateId): array
    {
        $out = array();
        foreach ($this->db->query(
            "SELECT name, source FROM `" . $this->prefix . "spintax_template` WHERE template_id <> " . (int) $templateId
        )->rows as $t) {
            foreach ($this->includeNames((string) $t['source']) as $name) {
                if ($this->templateIdByName($name) === $templateId) {
                    $out[] = (string) $t['name'];
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * Resolve an `#include` target name to a template id EXACTLY as the render
     * resolver does (same query + collation), so the cascade and render never
     * disagree about which template a name refers to.
     */
    private function templateIdByName(string $name): ?int
    {
        $q = $this->db->query(
            "SELECT template_id FROM `" . $this->prefix . "spintax_template` "
            . "WHERE name = '" . $this->db->escape($name) . "' ORDER BY template_id LIMIT 1"
        );
        return isset($q->row['template_id']) ? (int) $q->row['template_id'] : null;
    }

    /**
     * Raw `#include "name"` targets in a source (whole-line directive, same syntax
     * as the render parser). Names are matched to templates via SQL, which owns the
     * case/accent collation — so these are returned verbatim, not pre-folded.
     *
     * @return string[]
     */
    private function includeNames(string $source): array
    {
        if (!preg_match_all('/^[ \t]*#include\s+"([^"]+)"\s*$/mu', $source, $m)) {
            return array();
        }
        return array_map(static fn(string $n): string => trim($n), $m[1]);
    }
}
