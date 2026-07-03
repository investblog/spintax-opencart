<?php
/**
 * Phase 1.5 gate (live DB) — the zero-config first-run contract (§15):
 * a fresh install ships an enabled, understandable demo binding; a merchant gets
 * value in ONE pass (Dry run → Apply); and NOTHING is written until Apply — not
 * on install, not on a product save, not on Dry run. Restores meta; SKIPS off the
 * stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\Applier;
use Spintax\Core\Binding\SaveEventRunner;
use Spintax\Core\Binding\Walk;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Installer;

final class ZeroConfigDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private LanguageResolver $langs;
    /** @var array<string, string> */
    private array $snapshot = array();

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }
        $rows = $this->db->query("SELECT product_id, language_id, meta_description FROM `" . self::PREFIX . "product_description`")->rows;
        foreach ($rows as $r) {
            $this->snapshot["{$r['product_id']}:{$r['language_id']}"] = (string) $r['meta_description'];
        }
        $this->db->query("UPDATE `" . self::PREFIX . "product_description` SET meta_description = ''");

        (new Installer($this->db, self::PREFIX))->install(1);
        $this->langs = new LanguageResolver($this->db, self::PREFIX);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        (new Installer($this->db, self::PREFIX))->uninstall(true);
        foreach ($this->snapshot as $key => $val) {
            [$pid, $lang] = explode(':', $key);
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($val) . "' "
                . "WHERE product_id = " . (int) $pid . " AND language_id = " . (int) $lang
            );
        }
        $this->db->link()->close();
    }

    private function nonEmpty(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) c FROM `" . self::PREFIX . "product_description` WHERE meta_description <> ''"
        )->row['c'];
    }

    private function demoRow(): array
    {
        return $this->db->query("SELECT * FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = 'bind_demo01'")->row;
    }

    public function test_first_run_delivers_value_only_via_dry_run_then_apply(): void
    {
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $source = (string) $this->db->query("SELECT source FROM `" . self::PREFIX . "spintax_template` LIMIT 1")->row['source'];

        // 1) Install seeded an ENABLED, understandable demo binding — and wrote nothing.
        $demo = $this->demoRow();
        $this->assertSame('1', (string) $demo['status'], 'demo is enabled (visible/usable)');
        $this->assertSame('0', (string) $demo['trigger_on_save'], 'demo does not auto-write on save');
        $this->assertSame(0, $this->nonEmpty(), 'install writes nothing to the catalog');

        // 2) A product save writes NOTHING (trigger_on_save=0).
        $runner = new SaveEventRunner($this->db, self::PREFIX, $engine, $this->langs);
        $runner->onProductSave((int) $this->db->query("SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 1")->row['product_id']);
        $this->assertSame(0, $this->nonEmpty(), 'a product save must not write (zero-config safety)');

        // 3) Dry run previews but writes NOTHING.
        $applier = new Applier($this->db, self::PREFIX, $engine, $this->langs);
        $walk = new Walk($this->db, self::PREFIX, $applier, $this->langs);
        $dry = $walk->dryRun($this->demoRow(), $source);
        $this->assertGreaterThan(0, $dry['write'], 'dry run reports writes to come');
        $this->assertSame(0, $this->nonEmpty(), 'dry run must not write');

        // 4) Apply — the ONE explicit pass that delivers value.
        $lockTs = null;
        $guard = 0;
        do {
            $r = $walk->applyChunk($this->demoRow(), $source, $dry['dry_run_token'], 5, $lockTs);
            $this->assertArrayNotHasKey('error', $r);
            $lockTs = $r['lock_ts'];
        } while (!$r['done'] && ++$guard < 100);

        $this->assertSame($dry['write'], $this->nonEmpty(), 'Apply fills exactly the previewed cells');
        $this->assertGreaterThan(0, $this->nonEmpty(), 'merchant has value after one Dry run → Apply pass');
    }
}
