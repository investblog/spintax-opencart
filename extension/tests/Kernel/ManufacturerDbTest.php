<?php
/**
 * Manufacturer entity (spec §3.1) — seo_keyword only. Manufacturer has NO status
 * column (always enabled) and NO description/meta table: its name lives on the
 * base row and its only SEO surface is the oc_seo_url keyword. Verifies the slug
 * seeds from the base-table name on a real stand manufacturer. Self-skips off the
 * dev stand; restores the seo_url rows.
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
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class ManufacturerDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_mfr001';
    private const SOURCE = 'brand {alpha|beta} %name%';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $mfrId;
    /** @var array<int, string> */
    private array $snapshot = array();

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

        $row = $this->db->query("SELECT manufacturer_id FROM `" . self::PREFIX . "manufacturer` ORDER BY manufacturer_id LIMIT 1")->row;
        if (!$row) {
            $this->markTestSkipped('no manufacturers on the stand');
        }
        $this->mfrId = (int) $row['manufacturer_id'];
        foreach ($this->db->query("SELECT language_id, keyword FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND query = '" . $this->query() . "'")->rows as $r) {
            $this->snapshot[(int) $r['language_id']] = (string) $r['keyword'];
        }
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->clear();
        foreach ($this->snapshot as $langId => $kw) {
            $this->db->query("INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = " . (int) $langId . ", query = '" . $this->query() . "', keyword = '" . $this->db->escape($kw) . "'");
        }
        $this->db->link()->close();
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND query = '" . $this->query() . "'");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    private function query(): string
    {
        return 'manufacturer_id=' . $this->mfrId;
    }

    public function test_manufacturer_seeds_slug_from_base_name(): void
    {
        $this->assertNotNull(EntityRegistry::get('manufacturer'), 'manufacturer is registered');

        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('manufacturer'), 'seo_keyword', '');
        $result = $engine ? (new Applier($this->db, self::PREFIX, $engine, $this->langs))->applyTo($this->mfrId, $binding, self::SOURCE) : array();

        // The name comes from the base row (not per-language), so both languages
        // seed the SAME slug for this manufacturer.
        $name = (string) $this->db->query("SELECT name FROM `" . self::PREFIX . "manufacturer` WHERE manufacturer_id = {$this->mfrId}")->row['name'];
        $this->assertNotSame('', $name);

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $langId = (int) $langId;
            $this->assertSame(PlanCode::WROTE_SEEDED, $result["0:{$langId}"], "manufacturer seeds lang {$langId} (no status → always enabled)");
            $expected = $engine->renderSlug(self::SOURCE, array('name' => $name), $this->langs->activeLanguages()[$langId]);
            $stored = (string) $this->db->query("SELECT keyword FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND language_id = {$langId} AND query = '" . $this->query() . "'")->row['keyword'];
            $this->assertSame($expected, $stored, "manufacturer seo keyword matches the slug of its base-row name (lang {$langId})");
        }
    }
}
