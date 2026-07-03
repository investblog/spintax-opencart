<?php
/**
 * Phase-1 MVP applier: Product × description_column × all active languages.
 *
 * For each active language it renders the source (HTML for `description`, plain
 * for `meta_*`), reads the current target cell, runs the pure Planner, and — only
 * when the plan is a write — persists via **targeted direct SQL** (never
 * editProduct) and upserts the render signature. A single `cache->delete('product')`
 * is fired once after any write (supplied as a callback; §3.6).
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

use Spintax\Catalog\LanguageResolver;
use Spintax\Db\DbInterface;
use Spintax\Engine;

final class Applier
{
    private DbInterface $db;
    private string $prefix;
    private Engine $engine;
    private LanguageResolver $langs;
    private Planner $planner;
    /** @var callable|null */
    private $cacheFlush;

    /**
     * @param callable|null $cacheFlush Called once after any write (e.g. fn() => $cache->delete('product')).
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
        $this->cacheFlush = $cacheFlush;
    }

    /**
     * Apply a product binding across all active languages.
     *
     * @param string|null $source The RESOLVED source. `null` = the source record
     *                            could not be resolved (→ SKIP_SOURCE_NOT_FOUND);
     *                            `''` = a present-but-empty source (renders empty,
     *                            → SKIP_EMPTY_RENDER / WROTE_EMPTY per flags). §8.3.
     * @return array<int, string> language_id => PlanCode
     */
    public function applyToProduct(int $productId, ProductBinding $binding, ?string $source): array
    {
        $cells = $this->planProduct($productId, $binding, $source);
        $results = array();
        $wroteAny = false;

        foreach ($cells as $langId => $cell) {
            if (PlanCode::isWrite($cell['code'])) {
                $this->writeTarget($productId, (int) $langId, $binding->targetColumn, $cell['value']);
                $this->writeSignature($binding->bindingId, $productId, (int) $langId, sha1($cell['value']));
                $wroteAny = true;
            }
            $results[$langId] = $cell['code'];
        }

        if ($wroteAny && null !== $this->cacheFlush) {
            ($this->cacheFlush)();
        }

        return $results;
    }

    /**
     * Plan every active-language cell for a product WITHOUT writing. The single
     * decision path shared by apply, Bulk dry-run, and the Test panel (§8.4) —
     * so a preview can never disagree with a live write.
     *
     * @return array<int, array{language_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}>
     */
    public function planProduct(int $productId, ProductBinding $binding, ?string $source): array
    {
        if (!$binding->isValidColumn()) {
            // Reserved-field guard (§8.5.1): only §3.1 text columns are legal.
            throw new \InvalidArgumentException("illegal target column: {$binding->targetColumn}");
        }

        $productEnabled = $this->productEnabled($productId);
        $sourceFound = (null !== $source);   // present-but-empty is still "found"
        $renderSource = $source ?? '';
        $cells = array();

        foreach ($this->langs->activeLanguages() as $langId => $code) {
            // Cheap scope/source filters FIRST — never render for a rejected
            // entity (§8.3 ordering contract). Same Planner path as a full plan().
            $scopeReject = $this->planner->scopeReject(new PlanInput(
                entityEnabled: $productEnabled,
                sourceFound: $sourceFound,
            ));
            if (null !== $scopeReject) {
                $cells[$langId] = $this->cell((int) $langId, $scopeReject, $sourceFound, '', '', '', false);
                continue; // no render, no target read
            }

            $current = $this->readTarget($productId, (int) $langId, $binding->targetColumn);
            $vars = $this->entityVars($productId, (int) $langId);

            $rendered = $binding->isHtmlColumn()
                ? $this->engine->render($renderSource, $vars, $code)
                : $this->engine->renderPlain($renderSource, $vars, $code);

            $plan = $this->planner->plan(new PlanInput(
                entityEnabled: $productEnabled,
                sourceFound: $sourceFound,
                rendered: $rendered,
                currentTarget: $current,
                storedSignature: $this->readSignature($binding->bindingId, $productId, (int) $langId),
                regenerateOnSave: $binding->regenerateOnSave,
                autoSeedEmpty: $binding->autoSeedEmpty,
                preserveManualEdits: $binding->preserveManualEdits,
                clearOnEmpty: $binding->clearOnEmpty,
                isRequiredColumn: $binding->isRequiredColumn(),
            ));

            $value = (PlanCode::WROTE_EMPTY === $plan) ? '' : $rendered;
            $wouldChange = PlanCode::isWrite($plan) && ($value !== $current);
            $cells[$langId] = $this->cell((int) $langId, $plan, true, $rendered, $current, $value, $wouldChange);
        }

        return $cells;
    }

    /**
     * Single-cell rich detail for the Test panel (§10.2): the full Planner
     * contract for one (product, language). Same path as apply.
     *
     * @return array{language_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}
     */
    public function explainCell(int $productId, ProductBinding $binding, ?string $source, int $langId): array
    {
        $cells = $this->planProduct($productId, $binding, $source);
        return $cells[$langId] ?? $this->cell($langId, PlanCode::SKIP_LANGUAGE_NOT_INSTALLED, (null !== $source), '', '', '', false);
    }

    /**
     * §8.2 cold-start "Initialize from current value": stamp sha1(current) as the
     * baseline signature so a pre-existing value is adopted — NO catalog write.
     *
     * GUARDED: re-runs the plan for this exact (binding, source, product, language)
     * and stamps ONLY when the cell is genuinely `SKIP_COLD_START_MANUAL`. This
     * prevents baselining an arbitrary cell (e.g. an empty one that would seed, or
     * one already tracked) — the operation is only meaningful on cold start.
     *
     * @return array{success:bool}|array{error:string, code:string}
     */
    public function initBaseline(ProductBinding $binding, ?string $source, int $productId, int $langId): array
    {
        $cell = $this->explainCell($productId, $binding, $source, $langId);
        if (PlanCode::SKIP_COLD_START_MANUAL !== $cell['code']) {
            return array('error' => 'NOT_COLD_START', 'code' => $cell['code']);
        }
        $this->writeSignature($binding->bindingId, $productId, $langId, sha1($cell['current']));
        return array('success' => true);
    }

    /**
     * @return array{language_id:int, code:string, source_resolved:bool, rendered:string, current:string, value:string, would_change:bool}
     */
    private function cell(int $langId, string $code, bool $sourceResolved, string $rendered, string $current, string $value, bool $wouldChange): array
    {
        return array(
            'language_id' => $langId,
            'code' => $code,
            'source_resolved' => $sourceResolved,
            'rendered' => $rendered,
            'current' => $current,
            'value' => $value,
            'would_change' => $wouldChange,
        );
    }

    // --- catalog reads/writes (targeted direct SQL) -------------------------

    private function productEnabled(int $productId): bool
    {
        $q = $this->db->query("SELECT status FROM `{$this->prefix}product` WHERE product_id = " . (int) $productId);
        return isset($q->row['status']) && '1' === (string) $q->row['status'];
    }

    private function readTarget(int $productId, int $langId, string $column): string
    {
        // $column is whitelisted by ProductBinding::isValidColumn() before we get here.
        $q = $this->db->query(
            "SELECT `{$column}` AS v FROM `{$this->prefix}product_description` "
            . "WHERE product_id = " . (int) $productId . " AND language_id = " . (int) $langId
        );
        return (string) ($q->row['v'] ?? '');
    }

    /** @return array<string, string> */
    private function entityVars(int $productId, int $langId): array
    {
        $q = $this->db->query(
            "SELECT name FROM `{$this->prefix}product_description` "
            . "WHERE product_id = " . (int) $productId . " AND language_id = " . (int) $langId
        );
        return array('name' => (string) ($q->row['name'] ?? ''));
    }

    private function writeTarget(int $productId, int $langId, string $column, string $value): void
    {
        $this->db->query(
            "UPDATE `{$this->prefix}product_description` SET `{$column}` = '" . $this->db->escape($value) . "' "
            . "WHERE product_id = " . (int) $productId . " AND language_id = " . (int) $langId
        );
    }

    private function readSignature(string $bindingId, int $entityId, int $langId): ?string
    {
        $q = $this->db->query(
            "SELECT signature FROM `{$this->prefix}spintax_signature` "
            . "WHERE binding_id = '" . $this->db->escape($bindingId) . "' "
            . "AND entity_id = " . (int) $entityId . " AND language_id = " . (int) $langId . " AND store_id = -1"
        );
        return isset($q->row['signature']) ? (string) $q->row['signature'] : null;
    }

    private function writeSignature(string $bindingId, int $entityId, int $langId, string $signature): void
    {
        $this->db->query(
            "REPLACE INTO `{$this->prefix}spintax_signature` "
            . "(binding_id, entity_id, language_id, store_id, signature, date_modified) VALUES ("
            . "'" . $this->db->escape($bindingId) . "', "
            . (int) $entityId . ", " . (int) $langId . ", -1, "
            . "'" . $this->db->escape($signature) . "', NOW())"
        );
    }
}
