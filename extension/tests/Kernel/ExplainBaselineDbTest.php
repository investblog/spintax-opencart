<?php
/**
 * Phase 1.4 gate (live DB) — Test-panel explainCell() contract + the §8.2
 * "Initialize from current value" baseline mechanic. Snapshots/restores one
 * product cell; SKIPS off the stand.
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

final class ExplainBaselineDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_expl01';

    private MysqliDb $db;
    private Applier $applier;
    private int $productId;
    private int $langId;
    private string $metaSnapshot = '';

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $langs = new LanguageResolver($this->db, self::PREFIX);
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $this->applier = new Applier($this->db, self::PREFIX, $engine, $langs);

        $this->langId = (int) array_key_first($langs->activeLanguages());
        $this->productId = (int) $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1")->row['product_id'];
        $this->metaSnapshot = $this->readMeta();
        $this->clearSig();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->query(
            "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($this->metaSnapshot) . "' "
            . "WHERE product_id = {$this->productId} AND language_id = {$this->langId}"
        );
        $this->clearSig();
        foreach (Schema::dropStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $this->db->link()->close();
    }

    private function readMeta(): string
    {
        return (string) $this->db->query(
            "SELECT meta_description AS v FROM `" . self::PREFIX . "product_description` "
            . "WHERE product_id = {$this->productId} AND language_id = {$this->langId}"
        )->row['v'];
    }

    private function setMeta(string $v): void
    {
        $this->db->query(
            "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($v) . "' "
            . "WHERE product_id = {$this->productId} AND language_id = {$this->langId}"
        );
    }

    private function clearSig(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    public function test_explain_cell_returns_full_contract(): void
    {
        $this->setMeta(''); // empty → seed
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_description');
        $cell = $this->applier->explainCell($this->productId, $binding, 'Buy {this|that}', $this->langId);

        $this->assertSame($this->langId, $cell['language_id']);
        $this->assertTrue($cell['source_resolved']);
        $this->assertNotSame('', $cell['rendered']);
        $this->assertSame('', $cell['current']);
        $this->assertSame(PlanCode::WROTE_SEEDED, $cell['code']);
        $this->assertTrue($cell['would_change']);
    }

    public function test_initialize_from_current_value_baseline_flow(): void
    {
        // Pre-existing merchant copy, no signature → cold-start manual under regenerate.
        $this->setMeta('Hand-written merchant meta');
        $regen = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_description', autoSeedEmpty: false, regenerateOnSave: true, preserveManualEdits: true);

        $before = $this->applier->explainCell($this->productId, $regen, 'Engine text', $this->langId);
        $this->assertSame(PlanCode::SKIP_COLD_START_MANUAL, $before['code']);

        // Operator clicks "Initialize from current value" — guarded (must be
        // cold-start), stamps baseline, no write.
        $res = $this->applier->initBaseline($regen, 'Engine text', $this->productId, $this->langId);
        $this->assertTrue($res['success'] ?? false);
        $this->assertSame('Hand-written merchant meta', $this->readMeta(), 'initBaseline must NOT write to the catalog');

        // Now the current value IS the baseline → regenerate would overwrite it.
        $after = $this->applier->explainCell($this->productId, $regen, 'Engine text', $this->langId);
        $this->assertSame(PlanCode::WROTE_REGENERATED, $after['code']);

        // A later human edit diverges from the baseline → protected again.
        $this->setMeta('Merchant edited it again');
        $edited = $this->applier->explainCell($this->productId, $regen, 'Engine text', $this->langId);
        $this->assertSame(PlanCode::SKIP_MANUAL_EDIT_DETECTED, $edited['code']);
    }

    public function test_init_baseline_refused_when_not_cold_start(): void
    {
        // Empty target under seed mode → would WROTE_SEEDED, NOT cold-start.
        $this->setMeta('');
        $seed = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_description');

        $res = $this->applier->initBaseline($seed, 'Engine text', $this->productId, $this->langId);
        $this->assertSame('NOT_COLD_START', $res['error'] ?? '');
        $this->assertSame(PlanCode::WROTE_SEEDED, $res['code'] ?? '');

        // No signature was written.
        $sig = $this->db->query(
            "SELECT COUNT(*) c FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'"
        )->row['c'];
        $this->assertSame(0, (int) $sig, 'a refused initBaseline must stamp nothing');
    }
}
