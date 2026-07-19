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
use Spintax\Db\SqlIdentifiers;

final class TemplateRepository
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
     * @return array<int, array<string, mixed>> each with a `used_by` count.
     */
    public function list(): array
    {
        $sql = sprintf(
            'SELECT t.*, (SELECT COUNT(*) FROM %1$s b WHERE b.template_id = t.template_id) AS used_by '
            . 'FROM %2$s t ORDER BY t.name, t.template_id',
            $this->table('spintax_binding'),
            $this->table('spintax_template')
        );

        return $this->db->query($sql)->rows;
    }

    /** @return array<string, mixed>|null */
    public function get(int $templateId): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE template_id = %d',
            $this->table('spintax_template'),
            $templateId
        );

        $q = $this->db->query($sql);

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
            $sql = sprintf(
                "SELECT template_id FROM %s WHERE name = '%s' AND template_id <> %d LIMIT 1",
                $this->table('spintax_template'),
                $this->db->escape($name),
                $templateId
            );

            $dupe = $this->db->query($sql);
            if ($dupe->num_rows > 0) {
                return array('error' => 'A template with this name already exists — names must be unique for #include.');
            }
        }

        if ($templateId > 0) {
            // Union the dependents of the OLD name/source (before the write) with the
            // NEW ones (after) — so a RENAME that orphans an `#include` of the old name
            // still invalidates the template that pulled it in (§9.3), not only edits.
            $before = $this->affectedTemplateIds($templateId);
            $sql = sprintf(
                "UPDATE %s SET name = '%s', source = '%s', locale = '%s', date_modified = NOW() "
                . "WHERE template_id = %d",
                $this->table('spintax_template'),
                $this->db->escape($name),
                $this->db->escape($source),
                $this->db->escape($locale),
                $templateId
            );

            $this->db->query($sql);
            $after = $this->affectedTemplateIds($templateId);
            $dependents = $this->bumpBindings(array_values(array_unique(array_merge($before, $after))));
            return array('template_id' => $templateId, 'dependents' => $dependents);
        }

        $sql = sprintf(
            "INSERT INTO %s SET name = '%s', source = '%s', locale = '%s', "
            . "date_added = NOW(), date_modified = NOW()",
            $this->table('spintax_template'),
            $this->db->escape($name),
            $this->db->escape($source),
            $this->db->escape($locale)
        );

        $this->db->query($sql);
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
        $sql = sprintf(
            'DELETE FROM %s WHERE template_id = %d',
            $this->table('spintax_template'),
            $templateId
        );

        $this->db->query($sql);
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
        $inList = $this->intList($templateIds);
        if ('' === $inList) {
            return array();
        }
        $sql = sprintf(
            'SELECT binding_id FROM %s WHERE template_id IN (%s)',
            $this->table('spintax_binding'),
            $inList
        );

        $q = $this->db->query($sql);
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
            $inList = $this->intList($templateIds);
            $sql = sprintf(
                'UPDATE %s SET cache_version = cache_version + 1, date_modified = NOW() '
                . 'WHERE template_id IN (%s)',
                $this->table('spintax_binding'),
                $inList
            );

            $this->db->query($sql);
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
        $sql = sprintf('SELECT template_id, source FROM %s', $this->table('spintax_template'));

        foreach ($this->db->query($sql)->rows as $t) {
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
        $sql = sprintf(
            'SELECT name, source FROM %s WHERE template_id <> %d',
            $this->table('spintax_template'),
            $templateId
        );

        foreach ($this->db->query($sql)->rows as $t) {
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
        $sql = sprintf(
            "SELECT template_id FROM %s WHERE name = '%s' ORDER BY template_id LIMIT 1",
            $this->table('spintax_template'),
            $this->db->escape($name)
        );

        $q = $this->db->query($sql);
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
