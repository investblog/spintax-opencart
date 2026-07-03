<?php
/**
 * Phase 1.1 gate (live DB) — install()/uninstall() against real oc_event and
 * oc_user_group: tables created, 3 product events registered, permissions
 * granted to the admin group, demo template+binding seeded (disabled). Uninstall
 * deregisters events + permissions but keeps tables unless deleteData=true.
 * Snapshots + restores oc_user_group and cleans oc_event; SKIPS off the stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Db\MysqliDb;
use Spintax\Install\Installer;
use Spintax\Install\Schema;

final class InstallerDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const GROUP_ID = 1; // default "Administrator" user group

    private MysqliDb $db;
    private Installer $installer;
    private string $permSnapshot = '';

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }
        $this->installer = new Installer($this->db, self::PREFIX);
        $this->permSnapshot = (string) $this->db->query(
            "SELECT permission FROM `" . self::PREFIX . "user_group` WHERE user_group_id = " . self::GROUP_ID
        )->row['permission'];
        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->cleanup();
        // Restore the admin group's permission JSON verbatim.
        $this->db->query(
            "UPDATE `" . self::PREFIX . "user_group` SET permission = '" . $this->db->escape($this->permSnapshot) . "' "
            . "WHERE user_group_id = " . self::GROUP_ID
        );
        $this->db->link()->close();
    }

    private function cleanup(): void
    {
        foreach (Installer::EVENTS as [$code]) {
            $this->db->query("DELETE FROM `" . self::PREFIX . "event` WHERE code = '{$code}'");
        }
        foreach (Schema::dropStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
    }

    private function eventCount(): int
    {
        $codes = implode("','", array_map(static fn($e) => $e[0], Installer::EVENTS));
        return (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `" . self::PREFIX . "event` WHERE code IN ('{$codes}')"
        )->row['c'];
    }

    private function permission(): array
    {
        $json = (string) $this->db->query(
            "SELECT permission FROM `" . self::PREFIX . "user_group` WHERE user_group_id = " . self::GROUP_ID
        )->row['permission'];
        return json_decode($json, true) ?: array();
    }

    public function test_install_creates_tables_events_permissions_and_demo(): void
    {
        $this->installer->install(self::GROUP_ID);

        // Tables.
        foreach (Schema::tableNames(self::PREFIX) as $table) {
            $this->assertSame(1, $this->db->query("SHOW TABLES LIKE '{$table}'")->num_rows, "{$table} must exist");
        }

        // Events.
        $this->assertSame(3, $this->eventCount(), 'all three product events registered');
        $trig = $this->db->query(
            "SELECT `trigger`, `action` FROM `" . self::PREFIX . "event` WHERE code = 'spintax_seo_product_edit'"
        )->row;
        $this->assertSame('admin/model/catalog/product/editProduct/after', $trig['trigger']);
        $this->assertSame('extension/module/spintax_seo/eventProduct', $trig['action']);

        // Permissions.
        $perm = $this->permission();
        $this->assertContains(Installer::ROUTE, $perm['access']);
        $this->assertContains(Installer::ROUTE, $perm['modify']);

        // Demo seed (zero-config): enabled but trigger_on_save=0 — usable via Bulk
        // Apply, never auto-writes on a product save.
        $binding = $this->db->query(
            "SELECT status, trigger_on_save, source_mode, target_column, template_id FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = 'bind_demo01'"
        )->row;
        $this->assertSame('1', (string) $binding['status'], 'demo binding is seeded ENABLED (zero-config)');
        $this->assertSame('0', (string) $binding['trigger_on_save'], 'demo binding must NOT auto-write on product save');
        $this->assertSame('meta_description', $binding['target_column']);
        $this->assertGreaterThan(0, (int) $binding['template_id']);
        $this->assertSame(1, $this->db->query("SELECT template_id FROM `" . self::PREFIX . "spintax_template`")->num_rows);
    }

    public function test_install_is_idempotent(): void
    {
        $this->installer->install(self::GROUP_ID);
        $this->installer->install(self::GROUP_ID);

        $this->assertSame(3, $this->eventCount(), 're-install must not duplicate events');
        $this->assertSame(
            1,
            $this->db->query("SELECT binding_id FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = 'bind_demo01'")->num_rows,
            're-install must not duplicate the demo binding'
        );
        // Permission route appears exactly once.
        $access = $this->permission()['access'];
        $this->assertSame(1, count(array_keys($access, Installer::ROUTE, true)));
    }

    public function test_uninstall_keeps_tables_by_default_but_drops_events_and_perms(): void
    {
        $this->installer->install(self::GROUP_ID);
        $this->installer->uninstall(false);

        $this->assertSame(0, $this->eventCount(), 'events deregistered');
        $this->assertNotContains(Installer::ROUTE, $this->permission()['access'] ?? array(), 'permission revoked');
        // Non-destructive default: tables remain.
        $this->assertSame(1, $this->db->query("SHOW TABLES LIKE '" . self::PREFIX . "spintax_binding'")->num_rows);
    }

    public function test_uninstall_with_delete_data_drops_tables(): void
    {
        $this->installer->install(self::GROUP_ID);
        $this->installer->uninstall(true);

        $this->assertSame(0, $this->db->query("SHOW TABLES LIKE '" . self::PREFIX . "spintax_binding'")->num_rows);
    }
}
