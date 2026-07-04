<?php
/**
 * Phase 1.3 gate (live DB) — the MVP end-to-end on a REAL demo product:
 * seed-once across all active languages, safe re-run, manual-edit preservation,
 * and the required-column clear guard. Snapshots and restores the product's
 * description rows, and cleans its signature rows, so the stand is left as found.
 * SKIPS when the `db` service is unreachable (CI).
 *
 * This is the spec §15 Phase-1 exit demonstrated at the data layer:
 * "a merchant seeds unique product meta across a multi-language catalog,
 *  re-runs safely, and manual edits are preserved."
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\Applier;
use Spintax\Core\Binding\EntityBinding;
use Spintax\Core\Binding\EntityRegistry;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class ProductApplyDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_t35t01';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $productId;
    /** @var array<int, array{meta_title: string, meta_description: string}> */
    private array $snapshot = array();

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable (expected off the dev stand): ' . $e->getMessage());
        }

        // Ensure the extension tables exist (idempotent — mirrors install()).
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }

        $this->langs = new LanguageResolver($this->db, self::PREFIX);
        $langIds = array_keys($this->langs->activeLanguages());
        $this->assertGreaterThanOrEqual(2, count($langIds), 'stand must have >=2 active languages (run scripts/dev-provision.sh)');

        // Pick a real demo product and snapshot its description cells.
        $p = $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1");
        $this->productId = (int) $p->row['product_id'];
        foreach ($langIds as $langId) {
            $row = $this->db->query(
                "SELECT meta_title, meta_description FROM `" . self::PREFIX . "product_description` "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            )->row;
            $this->snapshot[$langId] = array(
                'meta_title' => (string) ($row['meta_title'] ?? ''),
                'meta_description' => (string) ($row['meta_description'] ?? ''),
            );
        }
        $this->clearSignatures();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        // Restore the product's original description cells.
        foreach ($this->snapshot as $langId => $vals) {
            foreach ($vals as $col => $val) {
                $this->db->query(
                    "UPDATE `" . self::PREFIX . "product_description` SET `{$col}` = '" . $this->db->escape($val) . "' "
                    . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
                );
            }
        }
        $this->clearSignatures();
        $this->db->link()->close();
    }

    private function clearSignatures(): void
    {
        $this->db->query(
            "DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'"
        );
    }

    private function engine(): Engine
    {
        return new Engine(new Parser(static fn(int $min, int $max): int => $min));
    }

    private function applier(Engine $engine): Applier
    {
        return new Applier($this->db, self::PREFIX, $engine, $this->langs);
    }

    private function setColumnAllLangs(string $col, string $value): void
    {
        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET `{$col}` = '" . $this->db->escape($value) . "' "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            );
        }
    }

    private function readColumn(string $col, int $langId): string
    {
        return (string) $this->db->query(
            "SELECT `{$col}` AS v FROM `" . self::PREFIX . "product_description` "
            . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
        )->row['v'];
    }

    public function test_seed_once_across_languages_then_preserve_on_rerun(): void
    {
        $this->setColumnAllLangs('meta_title', ''); // empty target so seed applies

        $engine = $this->engine();
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title'); // seed-once defaults
        $source = 'Купить %name% — {лучшая|отличная} цена';

        $first = $this->applier($engine)->applyTo($this->productId, $binding, $source);

        foreach ($first as $langId => $code) {
            $this->assertSame(PlanCode::WROTE_SEEDED, $code, "lang {$langId} should seed");
            $stored = $this->readColumn('meta_title', $langId);
            $this->assertNotSame('', $stored);
            $this->assertStringContainsString('лучшая цена', $stored);
            // Persisted value must equal the deterministic render for that language.
            $name = $this->db->query(
                "SELECT name FROM `" . self::PREFIX . "product_description` "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            )->row['name'];
            $expected = $engine->renderPlain($source, array('name' => $name), $this->langs->activeLanguages()[$langId]);
            $this->assertSame($expected, $stored);
        }

        // Re-run in seed mode → target non-empty → no rewrite.
        $second = $this->applier($engine)->applyTo($this->productId, $binding, $source);
        foreach ($second as $langId => $code) {
            $this->assertSame(PlanCode::SKIP_TARGET_NONEMPTY, $code);
        }
    }

    public function test_manual_edit_preserved_under_regenerate(): void
    {
        $this->setColumnAllLangs('meta_title', '');
        $engine = $this->engine();
        $source = 'Купить %name% сегодня';

        // Seed first (stamps the baseline signature).
        $seedBinding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title');
        $this->applier($engine)->applyTo($this->productId, $seedBinding, $source);

        // A human edits the field.
        $this->setColumnAllLangs('meta_title', 'HUMAN WROTE THIS');

        // Regenerate + preserve → must NOT overwrite the human edit.
        $regen = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title', autoSeedEmpty: false, regenerateOnSave: true, preserveManualEdits: true);
        $result = $this->applier($engine)->applyTo($this->productId, $regen, $source);

        foreach ($result as $langId => $code) {
            $this->assertSame(PlanCode::SKIP_MANUAL_EDIT_DETECTED, $code);
            $this->assertSame('HUMAN WROTE THIS', $this->readColumn('meta_title', $langId));
        }
    }

    public function test_disabled_entity_skips_without_rendering(): void
    {
        // §8.3 ordering: a cheap scope filter must reject BEFORE any render runs.
        $status = (string) $this->db->query(
            "SELECT status FROM `" . self::PREFIX . "product` WHERE product_id = {$this->productId}"
        )->row['status'];
        $this->db->query("UPDATE `" . self::PREFIX . "product` SET status = 0 WHERE product_id = {$this->productId}");

        try {
            $renderCalls = 0;
            $engine = new Engine(new Parser(function (int $min, int $max) use (&$renderCalls): int {
                ++$renderCalls;
                return $min;
            }));
            // Source with an enumeration — its RNG fires ONLY if a render happens.
            $result = $this->applier($engine)->applyTo($this->productId, new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title'), '{a|b}');

            foreach ($result as $langId => $code) {
                $this->assertSame(PlanCode::SKIP_OUT_OF_SCOPE_STATUS, $code);
            }
            $this->assertSame(0, $renderCalls, 'render must NOT run for a disabled entity (§8.3)');
        } finally {
            $this->db->query("UPDATE `" . self::PREFIX . "product` SET status = '" . $this->db->escape($status) . "' WHERE product_id = {$this->productId}");
        }
    }

    public function test_empty_present_source_renders_empty_not_source_not_found(): void
    {
        $this->setColumnAllLangs('meta_title', '');
        // '' = present-but-empty source (§8.3) => SKIP_EMPTY_RENDER, not SKIP_SOURCE_NOT_FOUND.
        $result = $this->applier($this->engine())->applyTo($this->productId, new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title'), '');
        foreach ($result as $code) {
            $this->assertSame(PlanCode::SKIP_EMPTY_RENDER, $code);
        }
    }

    public function test_null_source_is_source_not_found(): void
    {
        // null = source record could not be resolved => SKIP_SOURCE_NOT_FOUND.
        $result = $this->applier($this->engine())->applyTo($this->productId, new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_title'), null);
        foreach ($result as $code) {
            $this->assertSame(PlanCode::SKIP_SOURCE_NOT_FOUND, $code);
        }
    }

    public function test_required_column_never_cleared_on_empty_render(): void
    {
        $this->setColumnAllLangs('meta_title', 'EXISTING TITLE');
        $engine = $this->engine();

        // Source renders to empty (undefined conditional -> empty else branch).
        $source = '{?missingvar?something|}';
        $binding = new EntityBinding(
            self::BINDING,
            EntityRegistry::get('product'),
            'description_column',
            'meta_title',
            autoSeedEmpty: false,
            regenerateOnSave: true,
            preserveManualEdits: false,
            clearOnEmpty: true
        );

        $result = $this->applier($engine)->applyTo($this->productId, $binding, $source);

        foreach ($result as $langId => $code) {
            $this->assertSame(PlanCode::SKIP_CLEAR_FORBIDDEN_REQUIRED, $code);
            $this->assertSame('EXISTING TITLE', $this->readColumn('meta_title', $langId), 'required meta_title must never be cleared');
        }
    }
}
