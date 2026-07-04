<?php
/**
 * per_entity source mode (spec §15 Phase 2) — DB gates on the reviewer-agreed
 * contracts:
 *  - blank textarea = fallback: PerEntitySource::save DELETES empty values, and a
 *    missing row makes the Applier fall back to the binding's template (NOT a
 *    present-but-empty source → no accidental SKIP_EMPTY_RENDER);
 *  - snapshot invalidation: save/purge bump cache_version on per_entity bindings.
 * Runs on a real stand product; self-skips off the dev stand; restores everything.
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
use Spintax\Core\Binding\PerEntitySource;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class PerEntityDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_pe0001';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private PerEntitySource $src;
    private int $productId;
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
        $this->src = new PerEntitySource($this->db, self::PREFIX);

        $p = $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1");
        $this->productId = (int) $p->row['product_id'];
        foreach (array_keys($this->langs->activeLanguages()) as $l) {
            $this->snapshot[$l] = $this->readMeta((int) $l);
        }
        $this->cleanupRows();
        // A per_entity binding row (source_mode + cache_version) for the bump assertions.
        $this->db->query(
            "INSERT INTO `" . self::PREFIX . "spintax_binding` SET binding_id = '" . self::BINDING . "', "
            . "entity_type = 'product', target_kind = 'description_column', target_column = 'meta_description', "
            . "source_mode = 'per_entity', template_id = 0, status = 1, cache_version = 5, "
            . "date_added = NOW(), date_modified = NOW()"
        );
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->snapshot as $l => $v) {
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($v) . "' "
                . "WHERE product_id = {$this->productId} AND language_id = " . (int) $l
            );
        }
        $this->cleanupRows();
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = '" . self::BINDING . "'");
        $this->db->link()->close();
    }

    private function cleanupRows(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_source` WHERE entity_type = 'product' AND entity_id = {$this->productId}");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    private function readMeta(int $langId): string
    {
        return (string) ($this->db->query(
            "SELECT meta_description AS v FROM `" . self::PREFIX . "product_description` "
            . "WHERE product_id = {$this->productId} AND language_id = {$langId}"
        )->row['v'] ?? '');
    }

    private function cacheVersion(): int
    {
        return (int) $this->db->query(
            "SELECT cache_version AS v FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = '" . self::BINDING . "'"
        )->row['v'];
    }

    public function test_save_drops_empty_upserts_nonempty_and_bumps(): void
    {
        $langIds = array_keys($this->langs->activeLanguages());
        [$l1, $l2] = array($langIds[0], $langIds[1]);
        $before = $this->cacheVersion();

        // l1 gets a source; l2 is blank → must NOT be stored (fallback contract).
        $this->src->save('product', $this->productId, array($l1 => '  Override %name%  ', $l2 => '   '));

        $this->assertSame('  Override %name%  ', $this->src->get('product', $this->productId, $l1), 'non-empty stored verbatim');
        $this->assertNull($this->src->get('product', $this->productId, $l2), 'blank must be deleted, not stored empty');
        $this->assertSame(array($l1 => '  Override %name%  '), $this->src->loadAll('product', $this->productId));
        $this->assertGreaterThan($before, $this->cacheVersion(), 'save must bump per_entity binding cache_version');
    }

    public function test_apply_uses_override_else_template_fallback(): void
    {
        $langIds = array_keys($this->langs->activeLanguages());
        [$l1, $l2] = array($langIds[0], $langIds[1]);

        // Empty the target so a seed applies in both languages.
        foreach ($langIds as $l) {
            $this->db->query("UPDATE `" . self::PREFIX . "product_description` SET meta_description = '' WHERE product_id = {$this->productId} AND language_id = " . (int) $l);
        }
        // Override only l1; l2 has no stored source → must fall back to the template.
        $this->src->save('product', $this->productId, array($l1 => 'OVERRIDE %name%'));

        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $applier = new Applier($this->db, self::PREFIX, $engine, $this->langs);
        $binding = new EntityBinding(self::BINDING, EntityRegistry::get('product'), 'description_column', 'meta_description', sourceMode: 'per_entity');
        $fallback = 'FALLBACK %name%';

        $result = $applier->applyTo($this->productId, $binding, $fallback);

        foreach ($langIds as $l) {
            $this->assertSame(PlanCode::WROTE_SEEDED, $result[$l], "lang {$l} seeds");
        }
        $this->assertStringStartsWith('OVERRIDE ', $this->readMeta($l1), 'l1 uses the per-entity override');
        $this->assertStringStartsWith('FALLBACK ', $this->readMeta($l2), 'l2 (no override) falls back to the template');
    }

    public function test_purge_removes_sources_and_bumps(): void
    {
        $l1 = array_keys($this->langs->activeLanguages())[0];
        $this->src->save('product', $this->productId, array($l1 => 'x %name%'));
        $before = $this->cacheVersion();

        $this->src->purge('product', $this->productId);

        $this->assertNull($this->src->get('product', $this->productId, $l1), 'purge removes the row');
        $this->assertGreaterThan($before, $this->cacheVersion(), 'purge must bump cache_version too');
    }
}
