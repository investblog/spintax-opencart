<?php
/**
 * Self-scheduled cron (spec §6, §16 item 18) — DB gates on the CronRunner tick:
 * it no-ops before the interval elapses, advances last_run when due, and picks up
 * a stale binding and drives its walk. Uses the real Walk over a small demo slice;
 * snapshots + restores meta_description; self-skips off the dev stand.
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\Applier;
use Spintax\Core\Binding\Walk;
use Spintax\Core\Cron\CronRunner;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class CronDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_cron01';

    private MysqliDb $db;
    private CronRunner $runner;
    /** @var array<int, array<int, string>> product_id => lang => meta_description */
    private array $metaSnapshot = array();

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
        $langs = new LanguageResolver($this->db, self::PREFIX);
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $applier = new Applier($this->db, self::PREFIX, $engine, $langs);
        $walk = new Walk($this->db, self::PREFIX, $applier, $langs);
        $this->runner = new CronRunner($this->db, self::PREFIX, $walk);

        $this->cleanup();
        $this->setLastRun(0);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->metaSnapshot as $pid => $byLang) {
            foreach ($byLang as $langId => $val) {
                $this->db->query("UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($val) . "' WHERE product_id = " . (int) $pid . " AND language_id = " . (int) $langId);
            }
        }
        $this->cleanup();
        $this->db->link()->close();
    }

    private function cleanup(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = '" . self::BINDING . "'");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_walk` WHERE binding_id = '" . self::BINDING . "'");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_template` WHERE name = 'cron tpl'");
        $this->db->query("DELETE FROM `" . self::PREFIX . "setting` WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_last_run'");
    }

    private function setLastRun(int $ts): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "setting` WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_last_run'");
        $this->db->query("INSERT INTO `" . self::PREFIX . "setting` SET store_id = 0, `code` = 'spintax_seo', `key` = 'spintax_seo_last_run', `value` = '" . (int) $ts . "', serialized = '0'");
    }

    private function lastRun(): int
    {
        return (int) ($this->db->query("SELECT `value` FROM `" . self::PREFIX . "setting` WHERE `code` = 'spintax_seo' AND `key` = 'spintax_seo_last_run' AND store_id = 0")->row['value'] ?? 0);
    }

    private function makeStaleBinding(): void
    {
        $this->db->query("INSERT INTO `" . self::PREFIX . "spintax_template` SET name = 'cron tpl', source = 'cron {a|b} %name%', locale = '', date_added = NOW(), date_modified = NOW()");
        $tid = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];
        $this->db->query(
            "INSERT INTO `" . self::PREFIX . "spintax_binding` SET binding_id = '" . self::BINDING . "', "
            . "entity_type = 'product', target_kind = 'description_column', target_column = 'meta_description', "
            . "source_mode = 'template', template_id = {$tid}, status = 1, cadence = 'auto', cache_version = 3, chunk_size = 5, "
            . "date_added = NOW(), date_modified = NOW()"
        );
        // Snapshot the meta_description column so the cron's writes can be undone.
        foreach ($this->db->query("SELECT product_id, language_id, meta_description FROM `" . self::PREFIX . "product_description`")->rows as $r) {
            $this->metaSnapshot[(int) $r['product_id']][(int) $r['language_id']] = (string) $r['meta_description'];
        }
        // Empty a couple of cells so the seed has work to do.
        $this->db->query("UPDATE `" . self::PREFIX . "product_description` SET meta_description = '' WHERE product_id IN (SELECT product_id FROM (SELECT product_id FROM `" . self::PREFIX . "product` ORDER BY product_id LIMIT 3) x)");
    }

    public function test_not_due_before_the_interval_elapses(): void
    {
        $this->setLastRun(1000);
        $out = $this->runner->run(1000 + 10, 3600); // only 10s later
        $this->assertSame('not_due', $out['status']);
        $this->assertSame(1000, $this->lastRun(), 'last_run is untouched when not due');
    }

    public function test_runs_and_advances_last_run(): void
    {
        $this->setLastRun(0);
        $out = $this->runner->run(50_000, 3600);
        $this->assertSame('ran', $out['status']);
        $this->assertSame(50_000, $this->lastRun(), 'last_run advanced to now');
    }

    public function test_processes_a_stale_binding(): void
    {
        $this->makeStaleBinding();
        $out = $this->runner->run(50_000, 3600, 5, 50);

        $this->assertSame('ran', $out['status']);
        $ids = array_column($out['bindings'], 'binding_id');
        $this->assertContains(self::BINDING, $ids, 'the stale binding is picked up');

        // Its walk row now exists and advanced.
        $walk = $this->db->query("SELECT processed FROM `" . self::PREFIX . "spintax_walk` WHERE binding_id = '" . self::BINDING . "'")->row ?? array();
        $this->assertNotEmpty($walk, 'the cron created/advanced the walk row');
        $seeded = (int) $this->db->query("SELECT COUNT(*) AS c FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'")->row['c'];
        $this->assertGreaterThan(0, $seeded, 'the cron seeded at least one cell');
    }

    public function test_off_cadence_binding_is_never_run_by_cron(): void
    {
        // cadence='off' (the safe default, incl. the seeded demo binding) must NOT
        // be auto-applied by the cron — no "surprise catalog rewrite".
        $this->db->query("INSERT INTO `" . self::PREFIX . "spintax_template` SET name = 'cron tpl', source = 'x %name%', locale = '', date_added = NOW(), date_modified = NOW()");
        $tid = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];
        $this->db->query(
            "INSERT INTO `" . self::PREFIX . "spintax_binding` SET binding_id = '" . self::BINDING . "', "
            . "entity_type = 'product', target_kind = 'description_column', target_column = 'meta_keyword', "
            . "source_mode = 'template', template_id = {$tid}, status = 1, cadence = 'off', cache_version = 1, "
            . "date_added = NOW(), date_modified = NOW()"
        );

        $out = $this->runner->run(50_000, 3600);
        $this->assertNotContains(self::BINDING, array_column($out['bindings'], 'binding_id'), 'cadence=off is invisible to cron');
    }

    public function test_pause_releases_the_lock_so_the_next_tick_continues(): void
    {
        $this->makeStaleBinding(); // chunk_size 5, cadence auto

        // One tiny chunk → not finished; the cron must release its lock on pause.
        $out1 = $this->runner->run(50_000, 3600, 5, 1);
        $b1 = $out1['bindings'][0];
        $this->assertFalse($b1['done'], 'not done after a single small chunk');
        $walk1 = $this->db->query("SELECT lock_ts, processed FROM `" . self::PREFIX . "spintax_walk` WHERE binding_id = '" . self::BINDING . "'")->row;
        $this->assertSame(0, (int) $walk1['lock_ts'], 'the lock is released on pause (not left live to block next tick)');

        // Next tick continues from the cursor (would be WALK_LOCKED if the lock leaked).
        $this->setLastRun(0);
        $this->runner->run(50_001, 3600, 5, 1);
        $walk2 = $this->db->query("SELECT processed FROM `" . self::PREFIX . "spintax_walk` WHERE binding_id = '" . self::BINDING . "'")->row;
        $this->assertGreaterThan((int) $walk1['processed'], (int) $walk2['processed'], 'the walk advanced on the next tick');
    }
}
