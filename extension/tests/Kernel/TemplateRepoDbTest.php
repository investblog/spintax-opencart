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

    public function test_duplicate_nonempty_name_rejected(): void
    {
        $first = $this->repo->save(0, 'Shared Name', 'A');
        $this->assertArrayHasKey('template_id', $first);

        // A second template with the same non-empty name is rejected (#include unambiguity).
        $dup = $this->repo->save(0, 'Shared Name', 'B');
        $this->assertArrayHasKey('error', $dup);
        $this->assertArrayNotHasKey('template_id', $dup);

        // Empty names are allowed for as many templates as you like.
        $this->assertArrayHasKey('template_id', $this->repo->save(0, '', 'X'));
        $this->assertArrayHasKey('template_id', $this->repo->save(0, '', 'Y'));

        // Re-saving the first template under its own name is fine (self excluded).
        $this->assertArrayHasKey('template_id', $this->repo->save((int) $first['template_id'], 'Shared Name', 'A2'));
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

    public function test_editing_an_included_partial_cascades_to_the_includer_binding(): void
    {
        // A binding is bound to the INCLUDER; editing the INCLUDED partial must
        // still invalidate it (§9.3 — else a stale token/cron misses the change).
        $partial = (int) $this->repo->save(0, 'foot', 'FOOTER v1', '')['template_id'];
        $main = (int) $this->repo->save(0, 'main', "Body\n#include \"foot\"", '')['template_id'];
        $this->bindTo($main, 'bind_inc001', 'meta_description');

        $this->assertSame(1, $this->cacheVersion('bind_inc001'));

        $res = $this->repo->save($partial, 'foot', 'FOOTER v2', '');
        $this->assertSame(1, $res['dependents'], 'the includer binding counts as a transitive dependent');
        $this->assertSame(2, $this->cacheVersion('bind_inc001'), 'editing the partial bumped the includer binding');
    }

    public function test_transitive_include_chain_cascades(): void
    {
        // base <- mid <- top ; binding on top. Editing base must reach top's binding.
        $base = (int) $this->repo->save(0, 'base', 'B', '')['template_id'];
        $this->repo->save(0, 'mid', "#include \"base\"", '');
        $top = (int) $this->repo->save(0, 'top', "#include \"mid\"", '')['template_id'];
        $this->bindTo($top, 'bind_inc002', 'meta_description');

        $this->repo->save($base, 'base', 'B2', '');
        $this->assertSame(2, $this->cacheVersion('bind_inc002'), 'two-hop include chain invalidates the binding');
    }

    public function test_renaming_a_partial_invalidates_the_old_name_includer(): void
    {
        $partial = (int) $this->repo->save(0, 'footer', 'F', '')['template_id'];
        $main = (int) $this->repo->save(0, 'page', "#include \"footer\"", '')['template_id'];
        $this->bindTo($main, 'bind_ren001', 'meta_description');
        $this->assertSame(1, $this->cacheVersion('bind_ren001'));

        // Rename the partial — 'page' still says #include "footer" (now orphaned), so
        // its render output changed and its binding must be flagged stale.
        $this->repo->save($partial, 'footer_v2', 'F', '');
        $this->assertSame(2, $this->cacheVersion('bind_ren001'), 'rename invalidates the old-name includer');
    }

    public function test_deleting_an_included_partial_is_blocked(): void
    {
        $partial = (int) $this->repo->save(0, 'shared', 'X', '')['template_id'];
        $this->repo->save(0, 'consumer', "#include \"shared\"", ''); // includes it; no binding

        $refused = $this->repo->delete($partial);
        $this->assertSame('IN_USE', $refused['error'] ?? '', 'cannot delete a partial another template includes');
        $this->assertContains('consumer', $refused['included_by'] ?? array());
        $this->assertNotNull($this->repo->get($partial), 'the partial survives the refused delete');
    }
}
