<?php
/**
 * Phase 1.4 gate (live DB) — Template CRUD + the §6.3 cache-version cascade +
 * refuse-delete-while-referenced. Creates/drops the spintax tables; SKIPS off
 * the stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Template\TemplateRepository;
use Spintax\Db\MysqliDb;
use Spintax\Install\Schema;

final class TemplateRepoDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private TemplateRepository $repo;

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }
        foreach (Schema::dropStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        foreach (Schema::createStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $this->repo = new TemplateRepository($this->db, self::PREFIX);
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach (Schema::dropStatements(self::PREFIX) as $sql) {
            $this->db->query($sql);
        }
        $this->db->link()->close();
    }

    private function bindTo(int $templateId, string $bindingId, string $targetColumn = 'meta_description'): void
    {
        // Distinct target per binding — the uniq_binding_target index (§4.4)
        // correctly rejects two bindings fighting over the same cell.
        $this->db->query(
            "INSERT INTO `" . self::PREFIX . "spintax_binding` SET binding_id = '{$bindingId}', entity_type = 'product', "
            . "target_kind = 'description_column', target_column = '{$targetColumn}', source_mode = 'template', "
            . "template_id = " . (int) $templateId . ", cache_version = 1, date_added = NOW(), date_modified = NOW()"
        );
    }

    private function cacheVersion(string $bindingId): int
    {
        return (int) $this->db->query(
            "SELECT cache_version FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = '{$bindingId}'"
        )->row['cache_version'];
    }

    public function test_insert_get_list(): void
    {
        $saved = $this->repo->save(0, 'Tpl A', 'Buy {this|that}', '');
        $this->assertGreaterThan(0, $saved['template_id']);
        $this->assertSame(0, $saved['dependents']);

        $row = $this->repo->get($saved['template_id']);
        $this->assertSame('Tpl A', $row['name']);
        $this->assertSame('Buy {this|that}', $row['source']);

        $list = $this->repo->list();
        $this->assertCount(1, $list);
        $this->assertSame('0', (string) $list[0]['used_by']);
    }

    public function test_update_runs_cache_version_cascade(): void
    {
        $id = $this->repo->save(0, 'Shared', 'v1', '')['template_id'];
        $this->bindTo($id, 'bind_dep001', 'meta_description');
        $this->bindTo($id, 'bind_dep002', 'description');

        $this->assertSame(1, $this->cacheVersion('bind_dep001'));

        $res = $this->repo->save($id, 'Shared', 'v2 edited', '');

        $this->assertSame(2, $res['dependents'], 'two dependent bindings');
        $this->assertSame(2, $this->cacheVersion('bind_dep001'), 'cache_version bumped (§6.3)');
        $this->assertSame(2, $this->cacheVersion('bind_dep002'));
        // used_by reflected in the list.
        $this->assertSame('2', (string) $this->repo->list()[0]['used_by']);
    }

    public function test_delete_refused_while_referenced_then_allowed(): void
    {
        $id = $this->repo->save(0, 'Doomed', 'x', '')['template_id'];
        $this->bindTo($id, 'bind_dep003');

        $refused = $this->repo->delete($id);
        $this->assertSame('IN_USE', $refused['error'] ?? '');
        $this->assertContains('bind_dep003', $refused['bindings']);
        $this->assertNotNull($this->repo->get($id), 'template must survive a refused delete');

        // Remove the binding, then delete succeeds.
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = 'bind_dep003'");
        $ok = $this->repo->delete($id);
        $this->assertTrue($ok['success'] ?? false);
        $this->assertNull($this->repo->get($id));
    }
}
