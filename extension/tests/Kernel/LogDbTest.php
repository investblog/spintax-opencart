<?php
/**
 * Activity log (spec §15) — DB gates on ActivityLog: records an event, lists newest
 * first, skips pure no-ops, buckets result codes via PlanCode::category, and prunes
 * to a bound. The log is ephemeral operational data, so the suite owns the table
 * (cleared on setUp/tearDown). Self-skips off the dev stand.
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Log\ActivityLog;
use Spintax\Db\MysqliDb;
use Spintax\Install\Schema;

final class LogDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private ActivityLog $log;

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
        $this->db->query("TRUNCATE `" . self::PREFIX . "spintax_log`");
        $this->log = new ActivityLog($this->db, self::PREFIX);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->db->query("TRUNCATE `" . self::PREFIX . "spintax_log`");
        $this->db->link()->close();
    }

    public function test_records_and_lists_newest_first(): void
    {
        $this->log->record('bind_log001', 'save', 5, 2, 1, 0);
        $this->log->record('bind_log002', 'bulk', null, 3, 0, 1);

        $rows = $this->log->recent(10);
        $this->assertCount(2, $rows);
        $this->assertSame('bulk', $rows[0]['origin'], 'newest first');
        $this->assertSame('3', (string) $rows[0]['written']);
        $this->assertSame('bind_log001', $rows[1]['binding_id']);
    }

    public function test_pure_noop_is_not_logged(): void
    {
        $this->log->record('bind_log001', 'save', 5, 0, 0, 0);
        $this->assertCount(0, $this->log->recent(10), 'a no-op save writes no log row');
    }

    public function test_recordResult_buckets_codes(): void
    {
        $this->log->recordResult('bind_log001', 'bulk', null, array(
            PlanCode::WROTE_SEEDED,        // write
            PlanCode::SKIP_TARGET_NONEMPTY, // skip
            PlanCode::SKIP_ATTRIBUTE_DELETED, // blocked
        ));
        $row = $this->log->recent(1)[0];
        $this->assertSame('1', (string) $row['written']);
        $this->assertSame('1', (string) $row['skipped']);
        $this->assertSame('1', (string) $row['blocked']);
    }

    public function test_prune_keeps_newest_n(): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $this->log->record('bind_log001', 'bulk', null, 1, 0, 0);
        }
        $this->log->prune(3);
        $this->assertCount(3, $this->log->recent(100), 'prune trims to the newest N');
    }
}
