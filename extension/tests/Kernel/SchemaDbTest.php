<?php
/**
 * Phase 1.0b gate (live DB) — the DDL actually executes on MariaDB and yields
 * five InnoDB tables, then drops clean. Uses a throwaway prefix so it never
 * touches the real `oc_` schema. SKIPS when the `db` service is unreachable
 * (e.g. GitHub Actions), so it only runs on the live dev stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Install\Schema;

final class SchemaDbTest extends TestCase
{
    private const PREFIX = 'sxtest_';

    private \mysqli $db;

    protected function setUp(): void
    {
        if (!extension_loaded('mysqli')) {
            $this->markTestSkipped('mysqli not available');
        }
        mysqli_report(MYSQLI_REPORT_OFF);
        $db = @new \mysqli('db', 'opencart', 'opencart', 'opencart');
        if ($db->connect_errno) {
            $this->markTestSkipped('live db service not reachable (expected off the dev stand): ' . $db->connect_error);
        }
        $this->db = $db;
        $this->dropAll();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && !$this->db->connect_errno) {
            $this->dropAll();
            $this->db->close();
        }
    }

    private function dropAll(): void
    {
        foreach (Schema::dropStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
    }

    public function test_ddl_creates_five_innodb_tables(): void
    {
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->assertTrue($this->db->query($sql) !== false, 'DDL failed: ' . $this->db->error);
        }

        foreach (Schema::tableNames(self::PREFIX) as $table) {
            $res = $this->db->query("SHOW TABLE STATUS LIKE '{$table}'");
            $row = $res->fetch_assoc();
            $this->assertNotNull($row, "table {$table} must exist after DDL");
            $this->assertSame('InnoDB', $row['Engine'], "{$table} must be InnoDB");
        }
    }

    public function test_ddl_is_idempotent(): void
    {
        // IF NOT EXISTS means a second run is a no-op, not an error.
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->assertTrue($this->db->query($sql) !== false, 'second CREATE must be a no-op: ' . $this->db->error);
        }
    }

    public function test_binding_pk_rejects_duplicate(): void
    {
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $t = self::PREFIX . 'spintax_binding';
        $cols = "(`binding_id`,`entity_type`,`target_kind`,`source_mode`,`date_added`,`date_modified`)";
        $vals = "('bind_abc123','product','description_column','template',NOW(),NOW())";
        $this->assertTrue($this->db->query("INSERT INTO `{$t}` {$cols} VALUES {$vals}") !== false);
        // Duplicate PK must fail.
        $this->assertFalse(@$this->db->query("INSERT INTO `{$t}` {$cols} VALUES {$vals}"));
    }
}
