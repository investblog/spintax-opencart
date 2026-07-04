<?php
/**
 * Phase 1.1/1.3 gate (live DB) — the whole save-event body end to end (minus the
 * OpenCart event dispatch): Installer seeds the demo template+binding, we enable
 * it, empty a real product's meta_description, and SaveEventRunner::onEntitySave
 * seeds unique meta across all active languages. Restores everything; SKIPS off
 * the stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\EntityRegistry;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Binding\SaveEventRunner;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Installer;
use Spintax\Install\Schema;

final class SaveEventDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $productId;
    /** @var array<int, string> */
    private array $metaSnapshot = array();

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }

        (new Installer($this->db, self::PREFIX))->install(1); // tables + demo binding (disabled)
        $this->langs = new LanguageResolver($this->db, self::PREFIX);

        $this->productId = (int) $this->db->query(
            "SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1"
        )->row['product_id'];

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $this->metaSnapshot[$langId] = (string) $this->db->query(
                "SELECT meta_description AS v FROM `" . self::PREFIX . "product_description` "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            )->row['v'];
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '' "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            );
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->metaSnapshot as $langId => $val) {
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($val) . "' "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            );
        }
        (new Installer($this->db, self::PREFIX))->uninstall(true); // drop tables + events + perms
        $this->db->link()->close();
    }

    public function test_enabled_demo_binding_seeds_meta_on_product_save(): void
    {
        // Opt the demo binding into save-triggering (seeded enabled but trigger_on_save=0).
        $this->db->query(
            "UPDATE `" . self::PREFIX . "spintax_binding` SET status = '1', trigger_on_save = '1' WHERE binding_id = 'bind_demo01'"
        );
        $source = (string) $this->db->query(
            "SELECT source FROM `" . self::PREFIX . "spintax_template` LIMIT 1"
        )->row['source'];

        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $runner = new SaveEventRunner($this->db, self::PREFIX, $engine, $this->langs);

        $results = $runner->onEntitySave(EntityRegistry::get('product'), $this->productId);

        $this->assertArrayHasKey('bind_demo01', $results);
        foreach ($results['bind_demo01'] as $langId => $code) {
            $this->assertSame(PlanCode::WROTE_SEEDED, $code, "lang {$langId}");

            $stored = (string) $this->db->query(
                "SELECT meta_description AS v FROM `" . self::PREFIX . "product_description` "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            )->row['v'];
            $name = (string) $this->db->query(
                "SELECT name FROM `" . self::PREFIX . "product_description` "
                . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
            )->row['name'];
            $expected = $engine->renderPlain($source, array('name' => $name), $this->langs->activeLanguages()[$langId]);

            $this->assertNotSame('', $stored);
            $this->assertSame($expected, $stored, 'seeded meta must equal the rendered demo template');
        }
    }

    public function test_seeded_demo_binding_does_not_run_on_save(): void
    {
        // Installer seeds the demo ENABLED but with trigger_on_save=0 → a product
        // save must NOT auto-write it (zero-config safety, §15). Bulk Apply still can.
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $runner = new SaveEventRunner($this->db, self::PREFIX, $engine, $this->langs);

        $this->assertSame(array(), $runner->onEntitySave(EntityRegistry::get('product'), $this->productId), 'trigger_on_save=0 binding must not run on save');
        $this->assertSame('', (string) $this->db->query(
            "SELECT meta_description AS v FROM `" . self::PREFIX . "product_description` "
            . "WHERE product_id = {$this->productId} AND language_id = 1"
        )->row['v'], 'nothing written on save');
    }
}
