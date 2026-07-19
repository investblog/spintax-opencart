<?php
/**
 * Entity applier: <entity> × description_column × all active languages.
 *
 * For each active language it renders the source (HTML for HTML columns, plain
 * for meta_*), reads the current target cell, runs the pure Planner, and — only
 * when the plan is a write — persists via **targeted direct SQL** (never
 * editX) and upserts the render signature. The right cache group is flushed once
 * after any write (supplied as a `fn(string $group)` callback; §3.6).
 *
 * Every entity-specific fact (tables, id/status/name columns, cache group) comes
 * from the binding's {@see EntityType} descriptor — this class has no per-entity
 * branching.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Template\IncludeResolver;
use Spintax\Db\DbInterface;
use Spintax\Db\SqlIdentifiers;
use Spintax\Engine;

final class Applier
{
    use SqlIdentifiers;

    private DbInterface $db;
    private string $prefix;
    private Engine $engine;
    private LanguageResolver $langs;
    private Planner $planner;
    private PerEntitySource $perEntity;
    /** @var callable|null */
    private $cacheFlush;

    /**
     * @param callable|null $cacheFlush Called once after any write with the entity's
     *                                  cache group (e.g. fn(string $g) => $cache->delete($g)).
     */
    public function __construct(
        DbInterface $db,
        string $prefix,
        Engine $engine,
        LanguageResolver $langs,
        ?Planner $planner = null,
        ?callable $cacheFlush = null
    ) {
        $this->db = $db;
        $this->prefix = $prefix;
        $this->engine = $engine;
        $this->langs = $langs;
        $this->planner = $planner ?? new Planner();
        $this->perEntity = new PerEntitySource($db, $prefix);
        $this->cacheFlush = $cacheFlush;
    }

    /**
     * Apply a binding to one entity across all active languages.
     *
     * @param string|null $source The resolved template source — used directly in
     *                            `template` mode, and as the per-cell FALLBACK in
     *                            `per_entity` mode (when the entity has no stored
     *                            source for that language). `null` = unresolved
     *                            (→ SKIP_SOURCE_NOT_FOUND); `''` = present-but-empty
     *                            (renders empty → SKIP_EMPTY_RENDER / WROTE_EMPTY). §8.3.
     * @return array<int, string> language_id => PlanCode
     */
    public function applyTo(int $entityId, EntityBinding $binding, ?string $source): array
    {
        $cells = $this->planEntity($entityId, $binding, $source);
        $results = array();
        $wroteAny = false;

        $isSeo = $binding->isSeoKeyword();
        $query = $binding->entity->seoQueryPrefix . $entityId;

        // store_id + language_id ride on each cell (seo fans out per store; the
        // description path is a single store -1 cell per language).
        foreach ($cells as $key => $cell) {
            if (PlanCode::isWrite($cell['code'])) {
                if ($isSeo) {
                    $this->writeSeoKeyword((int) $cell['store_id'], (int) $cell['language_id'], $query, $cell['value']);
                } elseif ($binding->isEav()) {
                    $this->writeAttribute($entityId, $binding->attributeId, (int) $cell['language_id'], $cell['value']);
                } else {
                    $this->writeTarget($binding->entity, $entityId, (int) $cell['language_id'], $binding->targetColumn, $cell['value']);
                }
                $this->writeSignature($binding->bindingId, $entityId, (int) $cell['language_id'], sha1($cell['value']), (int) $cell['store_id']);
                $wroteAny = true;
            }
            $results[$key] = $cell['code'];
        }

        if ($wroteAny && null !== $this->cacheFlush) {
            ($this->cacheFlush)($binding->entity->cacheGroup);
        }

        return $results;
    }

    /**
     * Plan every active-language cell for one entity WITHOUT writing. The single
     * decision path shared by apply, Bulk dry-run, and the Test panel (§8.4) —
     * so a preview can never disagree with a live write.
     *
     * @return array<int, array{language_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}>
     */
    public function planEntity(int $entityId, EntityBinding $binding, ?string $source): array
    {
        $isSeo = $binding->isSeoKeyword();
        if (!$isSeo && !$binding->isEav() && !$binding->isValidColumn()) {
            // Reserved-field guard (§8.5.1): only §3.1 text columns are legal
            // (seo_keyword + eav_attribute have no description column).
            throw new \InvalidArgumentException("illegal target column: {$binding->targetColumn}");
        }

        $entityEnabled = $this->entityEnabled($binding->entity, $entityId);
        $query = $binding->entity->seoQueryPrefix . $entityId;
        // seo_keyword fans out across the entity's mapped stores (∩ the binding's
        // store_scope); description_column is not per-store (store -1 sentinel, §4.2).
        $stores = $isSeo ? $this->seoStores($binding->entity, $entityId, $binding->storeScope) : array(-1);

        $cells = array();
        foreach ($stores as $storeId) {
            foreach ($this->langs->activeLanguages() as $langId => $code) {
                $langId = (int) $langId;
                // Composite key for seo (store fan-out); bare language for the single
                // store-agnostic description_column pass.
                $key = $isSeo ? ($storeId . ':' . $langId) : $langId;
                $cells[$key] = $this->planCell($entityId, $binding, $source, (int) $storeId, $langId, (string) $code, $entityEnabled, $query, $isSeo);
            }
        }

        return $cells;
    }

    /**
     * Plan a single (store, language) cell — shared by every store/language in
     * planEntity. `$storeId` is -1 for description_column, a real store for seo.
     *
     * @return array{language_id:int, store_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}
     */
    private function planCell(int $entityId, EntityBinding $binding, ?string $source, int $storeId, int $langId, string $code, bool $entityEnabled, string $query, bool $isSeo): array
    {
        // Per-cell source: in per_entity mode the entity's own stored source wins
        // when present, else we fall back to $source (the binding's template). A
        // missing per-entity row = fallback, NOT an empty source.
        $cellSource = $source;
        if ($binding->isPerEntity()) {
            $override = $this->perEntity->get($binding->entity->type, $entityId, $langId);
            if (null !== $override) {
                $cellSource = $override;
            }
        }
        $sourceFound = (null !== $cellSource); // present-but-empty is still "found"
        $renderSource = $cellSource ?? '';

        // Cheap scope/source filters FIRST — never render for a rejected entity (§8.3).
        $scopeReject = $this->planner->scopeReject(new PlanInput(
            entityEnabled: $entityEnabled,
            sourceFound: $sourceFound,
        ));
        if (null !== $scopeReject) {
            return $this->cell($langId, $scopeReject, $sourceFound, '', '', '', false, $storeId);
        }

        $vars = $this->entityVars($binding->entity, $entityId, $langId);
        $collides = false;
        $attributeOk = true;

        // #include resolver (shared by the description, eav AND slug paths).
        $resolver = (false !== strpos($renderSource, '#include'))
            ? IncludeResolver::build($this->engine, fn (string $name): ?string => $this->templateSourceByName($name), $vars, $code)
            : null;

        if ($isSeo) {
            // seo_keyword: render a URL slug (§9.5); resolveSeoKeyword guards collisions
            // against the whole oc_seo_url table PER STORE (cross-language) + optional
            // -<id> disambiguation; collides=true → the Planner skips.
            $current = $this->readSeoKeyword($storeId, $langId, $query);
            $slug = $this->engine->renderSlug($renderSource, $vars, $code, 255, $resolver);
            $seo = $this->resolveSeoKeyword($storeId, $query, $slug, $binding->seoDisambiguate, $entityId);
            $rendered = $seo['keyword'];
            $collides = $seo['collides'];
        } elseif ($binding->isEav()) {
            // eav_attribute: a product custom attribute (oc_product_attribute).
            // Runtime resolve-and-verify (§8.5.5): a deleted attribute_id (or a
            // non-product entity slipping past the form via raw config) → the Planner
            // skips (SKIP_ATTRIBUTE_DELETED) rather than writing an orphan row.
            $attributeOk = ('product' === $binding->entity->type) && $this->attributeExists($binding->attributeId);
            $current = $this->readAttribute($entityId, $binding->attributeId, $langId);
            // Attribute `text` is a PLAIN field: OC HTML-escapes it on the storefront
            // (Twig autoescape, no |raw), so render plain — HTML-sanitizing here would
            // double-encode (`&` → `&amp;` shown literally). Same class as meta_*.
            $rendered = $this->engine->renderPlain($renderSource, $vars, $code, $resolver);
        } else {
            $current = $this->readTarget($binding->entity, $entityId, $langId, $binding->targetColumn);
            $rendered = $binding->isHtmlColumn()
                ? $this->engine->render($renderSource, $vars, $code, $resolver)
                : $this->engine->renderPlain($renderSource, $vars, $code, $resolver);
        }

        $plan = $this->planner->plan(new PlanInput(
            entityEnabled: $entityEnabled,
            sourceFound: $sourceFound,
            rendered: $rendered,
            currentTarget: $current,
            storedSignature: $this->readSignature($binding->bindingId, $entityId, $langId, $storeId),
            attributeOk: $attributeOk,
            regenerateOnSave: $binding->regenerateOnSave,
            autoSeedEmpty: $binding->autoSeedEmpty,
            preserveManualEdits: $binding->preserveManualEdits,
            clearOnEmpty: $binding->clearOnEmpty,
            // seo_keyword is a SEO-URL-source value — never clear it (§8.5 guard 7).
            isRequiredColumn: $isSeo ? true : $binding->isRequiredColumn(),
            isSeoKeyword: $isSeo,
            seoCollides: $collides,
            // Disambiguation already resolved in resolveSeoKeyword; residual collides → SKIP.
            seoDisambiguate: false,
        ));

        $value = (PlanCode::WROTE_EMPTY === $plan) ? '' : $rendered;
        $wouldChange = PlanCode::isWrite($plan) && ($value !== $current);
        return $this->cell($langId, $plan, true, $rendered, $current, $value, $wouldChange, $storeId);
    }

    /**
     * Single-cell rich detail for the Test panel (§10.2): the full Planner
     * contract for one (entity, language). Same path as apply.
     *
     * @return array{language_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}
     */
    public function explainCell(int $entityId, EntityBinding $binding, ?string $source, int $langId): array
    {
        $cells = $this->planEntity($entityId, $binding, $source);
        // description_column keys by language; seo_keyword keys by "store:lang", so
        // return the first cell for this language (the default/first store — the
        // Test panel previews one store).
        foreach ($cells as $cell) {
            if ((int) $cell['language_id'] === $langId) {
                return $cell;
            }
        }
        return $this->cell($langId, PlanCode::SKIP_LANGUAGE_NOT_INSTALLED, (null !== $source), '', '', '', false);
    }

    /**
     * §8.2 cold-start "Initialize from current value": stamp sha1(current) as the
     * baseline signature so a pre-existing value is adopted — NO catalog write.
     *
     * GUARDED: re-runs the plan for this exact (binding, source, entity, language)
     * and stamps ONLY when the cell is genuinely `SKIP_COLD_START_MANUAL`. This
     * prevents baselining an arbitrary cell (e.g. an empty one that would seed, or
     * one already tracked) — the operation is only meaningful on cold start.
     *
     * @return array{success:bool}|array{error:string, code:string}
     */
    public function initBaseline(EntityBinding $binding, ?string $source, int $entityId, int $langId): array
    {
        $cell = $this->explainCell($entityId, $binding, $source, $langId);
        if (PlanCode::SKIP_COLD_START_MANUAL !== $cell['code']) {
            return array('error' => 'NOT_COLD_START', 'code' => $cell['code']);
        }
        // Stamp for the SAME store the previewed cell belongs to (seo = its store,
        // description = -1) so readSignature during apply matches — hardcoding 0
        // would leave a non-default-store cell stuck on cold start forever.
        $this->writeSignature($binding->bindingId, $entityId, $langId, sha1($cell['current']), (int) $cell['store_id']);
        return array('success' => true);
    }

    /**
     * @return array{language_id:int, store_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}
     */
    private function cell(int $langId, string $code, bool $sourceResolved, string $rendered, string $current, string $value, bool $wouldChange, int $storeId = -1): array
    {
        return array(
            'language_id' => $langId,
            'store_id' => $storeId,
            'code' => $code,
            'source_resolved' => $sourceResolved,
            'rendered' => $rendered,
            'current' => $current,
            'value' => $value,
            'would_change' => $wouldChange,
        );
    }

    // --- catalog reads/writes (targeted direct SQL, entity-descriptor-driven) ---

    private function entityEnabled(EntityType $entity, int $entityId): bool
    {
        // Entities with no status column (e.g. manufacturer) are always enabled.
        if (!$entity->hasStatus()) {
            return true;
        }
        $sql = sprintf(
            "SELECT `%s` AS s FROM %s "
            . "WHERE `%s` = %d",
            $this->column($entity->statusColumn),
            $this->table($entity->baseTable),
            $this->column($entity->idColumn),
            $entityId
        );

        $q = $this->db->query($sql);
        return isset($q->row['s']) && '1' === (string) $q->row['s'];
    }

    private function readTarget(EntityType $entity, int $entityId, int $langId, string $column): string
    {
        // $column is whitelisted by EntityBinding::isValidColumn() (against the
        // entity descriptor) before we get here; base/id/table come from the descriptor.
        $sql = sprintf(
            "SELECT `%s` AS v FROM %s "
            . "WHERE `%s` = %d AND language_id = %d",
            $this->column($column),
            $this->table((string) $entity->descriptionTable),
            $this->column($entity->idColumn),
            $entityId,
            $langId
        );

        $q = $this->db->query($sql);
        return (string) ($q->row['v'] ?? '');
    }

    /**
     * The template variables for one entity/language. `%name%` is the canonical
     * display-name var, sourced from the entity's name/title column so one template
     * works across entities.
     *
     * @return array<string, string>
     */
    private function entityVars(EntityType $entity, int $entityId, int $langId): array
    {
        if (!$entity->hasDescriptionTable()) {
            // e.g. manufacturer — the name is on the base row, not per-language.
            $sql = sprintf(
                "SELECT `%s` AS n FROM %s "
                . "WHERE `%s` = %d",
                $this->column($entity->nameColumn),
                $this->table($entity->baseTable),
                $this->column($entity->idColumn),
                $entityId
            );

            $q = $this->db->query($sql);
            return array('name' => (string) ($q->row['n'] ?? ''));
        }
        $sql = sprintf(
            "SELECT `%s` AS n FROM %s "
            . "WHERE `%s` = %d AND language_id = %d",
            $this->column($entity->nameColumn),
            $this->table((string) $entity->descriptionTable),
            $this->column($entity->idColumn),
            $entityId,
            $langId
        );

        $q = $this->db->query($sql);
        return array('name' => (string) ($q->row['n'] ?? ''));
    }

    private function writeTarget(EntityType $entity, int $entityId, int $langId, string $column, string $value): void
    {
        $sql = sprintf(
            "UPDATE %s SET `%s` = '%s' "
            . "WHERE `%s` = %d AND language_id = %d",
            $this->table((string) $entity->descriptionTable),
            $this->column($column),
            $this->db->escape($value),
            $this->column($entity->idColumn),
            $entityId,
            $langId
        );

        $this->db->query($sql);
    }

    // --- eav_attribute target (oc_product_attribute, §8.5.5) ------------------

    /** Runtime resolve-and-verify (§8.5.5): does the attribute_id still exist? */
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

    /** Current attribute text for (product, attribute, language), '' if none. */
    private function readAttribute(int $productId, int $attributeId, int $langId): string
    {
        $sql = sprintf(
            "SELECT text FROM %s "
            . "WHERE product_id = %d AND attribute_id = %d AND language_id = %d",
            $this->table('product_attribute'),
            $productId,
            $attributeId,
            $langId
        );

        $q = $this->db->query($sql);
        return (string) ($q->row['text'] ?? '');
    }

    /** Upsert the attribute text (PK = product_id, attribute_id, language_id). */
    private function writeAttribute(int $productId, int $attributeId, int $langId, string $text): void
    {
        $sql = sprintf(
            "REPLACE INTO %s SET "
            . "product_id = %d, attribute_id = %d, "
            . "language_id = %d, text = '%s'",
            $this->table('product_attribute'),
            $productId,
            $attributeId,
            $langId,
            $this->db->escape($text)
        );

        $this->db->query($sql);
    }

    /** Resolve a `#include`d template's source by name (first match), null if none. */
    private function templateSourceByName(string $name): ?string
    {
        $sql = sprintf(
            "SELECT source FROM %s "
            . "WHERE name = '%s' ORDER BY template_id LIMIT 1",
            $this->table('spintax_template'),
            $this->db->escape($name)
        );

        $q = $this->db->query($sql);
        return isset($q->row['source']) ? (string) $q->row['source'] : null;
    }

    // --- seo_keyword target (oc_seo_url, §3.2 / §8.5.6) -----------------------

    /**
     * The stores a seo_keyword binding writes for this entity: the entity's mapped
     * stores (oc_<base>_to_store) ∩ the binding's store_scope ('ALL' = all mapped
     * stores; else a CSV of store ids). Unmapped entity → the default store (0).
     *
     * @return int[]
     */
    private function seoStores(EntityType $entity, int $entityId, string $storeScope): array
    {
        $sql = sprintf(
            "SELECT store_id FROM %s WHERE `%s` = %d",
            $this->table($entity->baseTable . '_to_store'),
            $this->column($entity->idColumn),
            $entityId
        );

        $q = $this->db->query($sql);
        $stores = array_map(static fn($r): int => (int) $r['store_id'], $q->rows);
        if (empty($stores)) {
            $stores = array(0); // no explicit mapping → default store
        }
        $scope = trim($storeScope);
        if ('' !== $scope && 'ALL' !== strtoupper($scope)) {
            $allowed = array_map('intval', array_filter(array_map('trim', explode(',', $scope)), static fn($v): bool => '' !== $v));
            $stores = array_values(array_intersect($stores, $allowed));
        }
        return $stores;
    }

    /** Current keyword stored for this (store, language, query), '' if none. */
    private function readSeoKeyword(int $storeId, int $langId, string $query): string
    {
        $sql = sprintf(
            "SELECT keyword FROM %s "
            . "WHERE store_id = %d AND language_id = %d"
            . " AND query = '%s'",
            $this->table('seo_url'),
            $storeId,
            $langId,
            $this->db->escape($query)
        );

        $q = $this->db->query($sql);
        return (string) ($q->row['keyword'] ?? '');
    }

    /**
     * §8.5.6 collision guard: is $keyword already used by a DIFFERENT query in this
     * store? Checked against the WHOLE oc_seo_url table. Deliberately NOT scoped by
     * language: core resolves keyword→entity per store, ACROSS languages
     * (catalog/controller/startup/seo_url.php: `WHERE keyword AND store_id`), so a
     * keyword must be unique per store across every language or the storefront
     * shadows one entity with another. Excluding our own `query` lets an entity
     * reuse its own slug across its languages.
     */
    private function seoKeywordTaken(int $storeId, string $keyword, string $query): bool
    {
        if ('' === $keyword) {
            return false;
        }
        $sql = sprintf(
            "SELECT seo_url_id FROM %s "
            . "WHERE store_id = %d"
            . " AND keyword = '%s' "
            . "AND query <> '%s' LIMIT 1",
            $this->table('seo_url'),
            $storeId,
            $this->db->escape($keyword),
            $this->db->escape($query)
        );

        $q = $this->db->query($sql);
        return $q->num_rows > 0;
    }

    /**
     * Resolve the keyword to write. `collides=true` means the keyword could NOT be
     * made unique (→ the Planner SKIPs, never overwriting a foreign URL). On a raw
     * collision with opt-in disambiguation, `-<entity_id>` is appended WITHIN the
     * varchar(255) cap and RE-CHECKED; if even that collides it still skips (§8.5.6).
     *
     * @return array{keyword:string, collides:bool}
     */
    private function resolveSeoKeyword(int $storeId, string $query, string $slug, bool $disambiguate, int $entityId): array
    {
        if ('' === $slug) {
            return array('keyword' => '', 'collides' => false); // empty → the empty-render path handles it
        }
        if (!$this->seoKeywordTaken($storeId, $slug, $query)) {
            return array('keyword' => $slug, 'collides' => false);
        }
        if (!$disambiguate) {
            return array('keyword' => $slug, 'collides' => true); // collision, no disambiguation → skip
        }
        // Reserve room for the suffix so MyISAM can't silently truncate it away.
        $suffix = '-' . $entityId;
        $keyword = mb_substr($slug, 0, 255 - mb_strlen($suffix)) . $suffix;
        if ($this->seoKeywordTaken($storeId, $keyword, $query)) {
            return array('keyword' => $slug, 'collides' => true); // even disambiguated collides → skip
        }
        return array('keyword' => $keyword, 'collides' => false);
    }

    /**
     * Delete-then-insert per (store, language, query) — mirrors core, avoids
     * stale/dup rows (§3.5). NON-ATOMIC on MyISAM (oc_seo_url has no transactions);
     * a crash between the two statements orphans the keyword — a follow-up walk
     * re-seeds it (§8.5.6). Never persists an empty keyword (defense-in-depth).
     */
    private function writeSeoKeyword(int $storeId, int $langId, string $query, string $keyword): void
    {
        if ('' === $keyword) {
            return; // an empty keyword is a broken URL — never write it (P2 guard)
        }
        $deleteSql = sprintf(
            "DELETE FROM %s WHERE store_id = %d"
            . " AND language_id = %d AND query = '%s'",
            $this->table('seo_url'),
            $storeId,
            $langId,
            $this->db->escape($query)
        );

        $this->db->query($deleteSql);

        $insertSql = sprintf(
            "INSERT INTO %s SET store_id = %d, language_id = %d"
            . ", query = '%s', keyword = '%s'",
            $this->table('seo_url'),
            $storeId,
            $langId,
            $this->db->escape($query),
            $this->db->escape($keyword)
        );

        $this->db->query($insertSql);
    }

    // --- signatures (store_id = -1 for description_column; real store for seo) --

    private function readSignature(string $bindingId, int $entityId, int $langId, int $storeId = -1): ?string
    {
        $sql = sprintf(
            "SELECT signature FROM %s "
            . "WHERE binding_id = '%s' "
            . "AND entity_id = %d AND language_id = %d AND store_id = %d",
            $this->table('spintax_signature'),
            $this->db->escape($bindingId),
            $entityId,
            $langId,
            $storeId
        );

        $q = $this->db->query($sql);
        return isset($q->row['signature']) ? (string) $q->row['signature'] : null;
    }

    private function writeSignature(string $bindingId, int $entityId, int $langId, string $signature, int $storeId = -1): void
    {
        $sql = sprintf(
            "REPLACE INTO %s "
            . "(binding_id, entity_id, language_id, store_id, signature, date_modified) VALUES ("
            . "'%s', "
            . "%d, %d, %d, "
            . "'%s', NOW())",
            $this->table('spintax_signature'),
            $this->db->escape($bindingId),
            $entityId,
            $langId,
            $storeId,
            $this->db->escape($signature)
        );

        $this->db->query($sql);
    }
}
