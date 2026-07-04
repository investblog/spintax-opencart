<?php
/**
 * eav_attribute target (spec §3.1 / §8.5.5) — DB gates on a real stand product's
 * oc_product_attribute rows: seed the attribute text from a template, and the
 * runtime resolve-and-verify guard (a deleted/non-existent attribute_id → skip,
 * never an orphan row). Product-only, per-language. Snapshots + restores the rows;
 * self-skips off the dev stand.
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

final class EavDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_eav001';
    private const SOURCE = 'attrtest {alpha|beta} & %name%'; // the & guards against HTML double-encoding

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $productId;
    private int $attributeId;
    /** @var array<int, string> language_id => text */
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

        $this->productId = (int) $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1")->row['product_id'];
        $attr = $this->db->query("SELECT attribute_id FROM `" . self::PREFIX . "attribute` ORDER BY attribute_id LIMIT 1")->row;
        if (!$attr) {
            $this->markTestSkipped('no attributes on the stand');
        }
        $this->attributeId = (int) $attr['attribute_id'];

        foreach ($this->db->query("SELECT language_id, text FROM `" . self::PREFIX . "product_attribute` WHERE product_id = {$this->productId} AND attribute_id = {$this->attributeId}")->rows as $r) {
            $this->snapshot[(int) $r['language_id']] = (string) $r['text'];
        }
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->clear();
        foreach ($this->snapshot as $langId => $text) {
            $this->db->query("REPLACE INTO `" . self::PREFIX . "product_attribute` SET product_id = {$this->productId}, attribute_id = {$this->attributeId}, language_id = " . (int) $langId . ", text = '" . $this->db->escape($text) . "'");
        }
        $this->db->link()->close();
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "product_attribute` WHERE product_id = {$this->productId} AND attribute_id = {$this->attributeId}");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    private function engine(): Engine
    {
        return new Engine(new Parser(static fn(int $min, int $max): int => $min));
    }

    private function binding(int $attributeId): EntityBinding
    {
        return new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'eav_attribute', '', attributeId: $attributeId);
    }

    private function text(int $langId): string
    {
        return (string) ($this->db->query("SELECT text FROM `" . self::PREFIX . "product_attribute` WHERE product_id = {$this->productId} AND attribute_id = {$this->attributeId} AND language_id = {$langId}")->row['text'] ?? '');
    }

    public function test_seeds_the_attribute_text(): void
    {
        $engine = $this->engine();
        $result = (new Applier($this->db, self::PREFIX, $engine, $this->langs))->applyTo($this->productId, $this->binding($this->attributeId), self::SOURCE);

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $langId = (int) $langId;
            $this->assertSame(PlanCode::WROTE_SEEDED, $result[$langId], "lang {$langId} seeds the attribute");
            $name = (string) $this->db->query("SELECT name FROM `" . self::PREFIX . "product_description` WHERE product_id = {$this->productId} AND language_id = {$langId}")->row['name'];
            // Plain render (OC escapes attribute text on output — must NOT be pre-encoded).
            $expected = $engine->renderPlain(self::SOURCE, array('name' => $name), $this->langs->activeLanguages()[$langId]);
            $this->assertSame($expected, $this->text($langId), "attribute text matches the plain render (lang {$langId})");
            $this->assertStringContainsString(' & ', $this->text($langId), 'the & is stored raw, not HTML-encoded (no double-encode on output)');
        }
    }

    public function test_deleted_attribute_is_skipped_not_orphaned(): void
    {
        // A binding pointing at a non-existent attribute id (deleted after authoring).
        $result = (new Applier($this->db, self::PREFIX, $this->engine(), $this->langs))->applyTo($this->productId, $this->binding(999_999), self::SOURCE);

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $this->assertSame(PlanCode::SKIP_ATTRIBUTE_DELETED, $result[(int) $langId], 'a deleted attribute is skipped, never written');
        }
        $orphans = (int) $this->db->query("SELECT COUNT(*) AS c FROM `" . self::PREFIX . "product_attribute` WHERE product_id = {$this->productId} AND attribute_id = 999999")->row['c'];
        $this->assertSame(0, $orphans, 'no orphan attribute row is created');
    }
}
