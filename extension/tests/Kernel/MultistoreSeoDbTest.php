<?php
/**
 * Multistore seo_keyword (spec §4.3 / Phase 3.3) — a binding fans out across the
 * entity's mapped stores. Maps a real stand product to two stores and verifies the
 * SEO-URL slug is written for BOTH, and that store_scope restricts the set.
 * Snapshots + restores product_to_store and seo_url; self-skips off the dev stand.
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\Applier;
use Spintax\Core\Binding\EntityBinding;
use Spintax\Core\Binding\EntityRegistry;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class MultistoreSeoDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_ms0001';
    private const TEST_STORE = 7; // an arbitrary extra store id (need not exist in oc_store)
    private const SOURCE = 'multistoretest %name%';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $productId;
    /** @var int[] */
    private array $storeSnapshot = array();

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable (expected off the dev stand): ' . $e->getMessage());
        }
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $this->langs = new LanguageResolver($this->db, self::PREFIX);
        $this->productId = (int) $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1")->row['product_id'];

        // Snapshot + set the product's store mapping to {0, TEST_STORE}.
        foreach ($this->db->query("SELECT store_id FROM `" . self::PREFIX . "product_to_store` WHERE product_id = {$this->productId}")->rows as $r) {
            $this->storeSnapshot[] = (int) $r['store_id'];
        }
        $this->db->query("DELETE FROM `" . self::PREFIX . "product_to_store` WHERE product_id = {$this->productId}");
        foreach (array(0, self::TEST_STORE) as $s) {
            $this->db->query("INSERT INTO `" . self::PREFIX . "product_to_store` SET product_id = {$this->productId}, store_id = {$s}");
        }
        $this->clearSeo();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->clearSeo();
        $this->db->query("DELETE FROM `" . self::PREFIX . "product_to_store` WHERE product_id = {$this->productId}");
        foreach ($this->storeSnapshot as $s) {
            $this->db->query("INSERT INTO `" . self::PREFIX . "product_to_store` SET product_id = {$this->productId}, store_id = " . (int) $s);
        }
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
        $this->db->link()->close();
    }

    private function clearSeo(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "seo_url` WHERE query = '" . $this->query() . "' AND store_id IN (0, " . self::TEST_STORE . ")");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    private function query(): string
    {
        return 'product_id=' . $this->productId;
    }

    private function applyWith(string $storeScope): void
    {
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'seo_keyword', '', storeScope: $storeScope);
        (new Applier($this->db, self::PREFIX, $engine, $this->langs))->applyTo($this->productId, $binding, self::SOURCE);
    }

    private function keyword(int $storeId, int $langId): string
    {
        return (string) ($this->db->query("SELECT keyword FROM `" . self::PREFIX . "seo_url` WHERE store_id = {$storeId} AND language_id = {$langId} AND query = '" . $this->query() . "'")->row['keyword'] ?? '');
    }

    public function test_seo_writes_every_mapped_store(): void
    {
        $this->clearSeo();
        $this->applyWith('ALL');

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $langId = (int) $langId;
            $this->assertNotSame('', $this->keyword(0, $langId), "default store seeded (lang {$langId})");
            $this->assertNotSame('', $this->keyword(self::TEST_STORE, $langId), "the second mapped store seeded (lang {$langId})");
        }
    }

    public function test_store_scope_restricts_the_fan_out(): void
    {
        $this->clearSeo();
        $this->applyWith((string) self::TEST_STORE); // only the extra store

        $this->assertNotSame('', $this->keyword(self::TEST_STORE, 1), 'the scoped store is written');
        $this->assertSame('', $this->keyword(0, 1), 'a store outside store_scope is NOT written');
    }
}
