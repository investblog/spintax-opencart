<?php
/**
 * seo_keyword target (spec §3.2 / §8.5.6) — DB gates on a real stand product's
 * oc_seo_url row: seed the slug keyword, skip on collision against a foreign URL,
 * and append -<entity_id> under opt-in disambiguation. Snapshots + restores the
 * product's seo_url rows; self-skips off the dev stand.
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

final class SeoKeywordDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_seo001';
    private const FOREIGN_QUERY = 'product_id=990099'; // a fake other entity for collisions
    private const SOURCE = 'spintaxtest {alpha|beta} %name%';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private int $productId;
    /** @var array<int, array{seo_url_id:int, keyword:string}> */
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
        // Snapshot + clear this product's existing seo_url rows so we start clean.
        foreach ($this->db->query("SELECT language_id, seo_url_id, keyword FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND query = '" . $this->query() . "'")->rows as $r) {
            $this->snapshot[(int) $r['language_id']] = array('seo_url_id' => (int) $r['seo_url_id'], 'keyword' => (string) $r['keyword']);
        }
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->clear();
        // Restore the product's original seo_url rows.
        foreach ($this->snapshot as $langId => $orig) {
            $this->db->query(
                "INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = " . (int) $langId
                . ", query = '" . $this->query() . "', keyword = '" . $this->db->escape($orig['keyword']) . "'"
            );
        }
        $this->db->link()->close();
    }

    private function clear(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND query IN ('" . $this->query() . "', '" . self::FOREIGN_QUERY . "')");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    private function query(): string
    {
        return 'product_id=' . $this->productId;
    }

    private function engine(): Engine
    {
        return new Engine(new Parser(static fn(int $min, int $max): int => $min));
    }

    private function applier(Engine $engine): Applier
    {
        return new Applier($this->db, self::PREFIX, $engine, $this->langs);
    }

    private function binding(bool $disambiguate = false): EntityBinding
    {
        return new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'seo_keyword', '', seoDisambiguate: $disambiguate);
    }

    private function expectedSlug(Engine $engine, int $langId): string
    {
        $name = (string) $this->db->query("SELECT name FROM `" . self::PREFIX . "product_description` WHERE product_id = {$this->productId} AND language_id = {$langId}")->row['name'];
        return $engine->renderSlug(self::SOURCE, array('name' => $name), $this->langs->activeLanguages()[$langId]);
    }

    private function keyword(int $langId): string
    {
        return (string) ($this->db->query("SELECT keyword FROM `" . self::PREFIX . "seo_url` WHERE store_id = 0 AND language_id = {$langId} AND query = '" . $this->query() . "'")->row['keyword'] ?? '');
    }

    public function test_seed_writes_the_slug_keyword(): void
    {
        $engine = $this->engine();
        $result = $this->applier($engine)->applyTo($this->productId, $this->binding(), self::SOURCE);

        foreach (array_keys($this->langs->activeLanguages()) as $langId) {
            $langId = (int) $langId;
            $this->assertSame(PlanCode::WROTE_SEEDED, $result["0:{$langId}"], "lang {$langId} seeds a keyword");
            $expected = $this->expectedSlug($engine, $langId);
            $this->assertNotSame('', $expected);
            $this->assertSame($expected, $this->keyword($langId), "seo_url keyword matches the rendered slug (lang {$langId})");
        }

        // Re-run in seed mode → keyword now non-empty → no rewrite.
        $second = $this->applier($engine)->applyTo($this->productId, $this->binding(), self::SOURCE);
        $this->assertSame(PlanCode::SKIP_TARGET_NONEMPTY, $second['0:1']);
    }

    public function test_collision_skips_without_disambiguation(): void
    {
        $engine = $this->engine();
        $slug = $this->expectedSlug($engine, 1);
        // A foreign entity already owns this exact keyword in (store 0, language 1).
        $this->db->query("INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = 1, query = '" . self::FOREIGN_QUERY . "', keyword = '" . $this->db->escape($slug) . "'");

        $result = $this->applier($engine)->applyTo($this->productId, $this->binding(false), self::SOURCE);

        $this->assertSame(PlanCode::SKIP_SEO_KEYWORD_COLLISION, $result['0:1'], 'collision → skip, never overwrite a foreign URL');
        $this->assertSame('', $this->keyword(1), 'our product gets no keyword on a skipped collision');
    }

    public function test_collision_is_cross_language(): void
    {
        // A foreign entity owns our lang-1 slug but in a DIFFERENT language (2).
        // Core resolves keyword→entity per store across languages, so this must
        // still be treated as a collision (P1).
        $engine = $this->engine();
        $slug = $this->expectedSlug($engine, 1);
        $this->db->query("INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = 2, query = '" . self::FOREIGN_QUERY . "', keyword = '" . $this->db->escape($slug) . "'");

        $result = $this->applier($engine)->applyTo($this->productId, $this->binding(false), self::SOURCE);

        $this->assertSame(PlanCode::SKIP_SEO_KEYWORD_COLLISION, $result['0:1'], 'a same-slug URL in another language is still a collision');
        $this->assertSame('', $this->keyword(1));
    }

    public function test_empty_slug_never_clears_the_url(): void
    {
        // Our product already has a good keyword we've been tracking.
        $this->db->query("INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = 1, query = '" . $this->query() . "', keyword = 'keep-this-url'");
        $this->db->query(
            "REPLACE INTO `" . self::PREFIX . "spintax_signature` (binding_id, entity_id, language_id, store_id, signature, date_modified) "
            . "VALUES ('" . self::BINDING . "', {$this->productId}, 1, 0, SHA1('keep-this-url'), NOW())"
        );

        // regenerate + clear_on_empty, but the source slugifies to nothing.
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'seo_keyword', '', regenerateOnSave: true, clearOnEmpty: true);
        $result = $this->applier($this->engine())->applyTo($this->productId, $binding, '!!!');

        $this->assertSame(PlanCode::SKIP_CLEAR_FORBIDDEN_REQUIRED, $result['0:1'], 'an empty slug must NOT clear the SEO URL');
        $this->assertSame('keep-this-url', $this->keyword(1), 'the existing keyword survives an empty render');
    }

    public function test_disambiguation_appends_entity_id(): void
    {
        $engine = $this->engine();
        $slug = $this->expectedSlug($engine, 1);
        $this->db->query("INSERT INTO `" . self::PREFIX . "seo_url` SET store_id = 0, language_id = 1, query = '" . self::FOREIGN_QUERY . "', keyword = '" . $this->db->escape($slug) . "'");

        $result = $this->applier($engine)->applyTo($this->productId, $this->binding(true), self::SOURCE);

        $this->assertTrue(PlanCode::isWrite($result['0:1']), 'disambiguation still writes a URL');
        $this->assertSame($slug . '-' . $this->productId, $this->keyword(1), 'collision + disambiguate → slug-<entity_id>');
    }
}
