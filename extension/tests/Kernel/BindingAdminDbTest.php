<?php
/**
 * Phase 1.4 gate (live DB) — admin binding CRUD + §8.5 guards: legal save,
 * illegal (entity,target) rejection, uniq_binding_target duplicate handling,
 * and delete purging the binding's own walk/signature rows.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Binding\BindingAdmin;
use Spintax\Db\MysqliDb;
use Spintax\Install\Schema;

final class BindingAdminDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private BindingAdmin $admin;
    private int $templateId;

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
        $this->db->query("INSERT INTO `" . self::PREFIX . "spintax_template` SET name='T', source='x', date_added=NOW(), date_modified=NOW()");
        $this->templateId = (int) $this->db->query("SELECT LAST_INSERT_ID() AS id")->row['id'];
        $this->admin = new BindingAdmin($this->db, self::PREFIX);
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

    private function legalData(string $column = 'meta_description'): array
    {
        return array(
            'entity_type' => 'product',
            'target_kind' => 'description_column',
            'target_column' => $column,
            'source_mode' => 'template',
            'template_id' => $this->templateId,
            'auto_seed_empty' => 1,
            'preserve_manual_edits' => 1,
            'status' => 1,
        );
    }

    public function test_legal_targets_for_product(): void
    {
        $targets = array_column($this->admin->legalTargets('product'), 'value');
        $this->assertSame(array('meta_title', 'meta_description', 'meta_keyword', 'description'), $targets);
        // Category + Information are registered Phase-2 entities (same column set).
        $this->assertSame(array('meta_title', 'meta_description', 'meta_keyword', 'description'), array_column($this->admin->legalTargets('category'), 'value'));
        $this->assertSame(array('meta_title', 'meta_description', 'meta_keyword', 'description'), array_column($this->admin->legalTargets('information'), 'value'));
        // Manufacturer is registered but has NO description columns (seo_keyword only).
        $this->assertSame(array(), $this->admin->legalTargets('manufacturer'), 'manufacturer has no description columns');
    }

    public function test_save_legal_binding_generates_id(): void
    {
        $res = $this->admin->save($this->legalData());
        $this->assertMatchesRegularExpression('/^bind_[a-z0-9]{6}$/', $res['binding_id'] ?? '');
        $this->assertCount(1, $this->admin->all());

        $row = $this->admin->find($res['binding_id']);
        $this->assertSame('meta_description', $row['target_column']);
        $this->assertSame('1', (string) $row['status']);
    }

    public function test_illegal_target_rejected(): void
    {
        $data = $this->legalData('price'); // not a §3.1 text column
        $res = $this->admin->save($data);
        $this->assertArrayHasKey('errors', $res);
        $this->assertArrayHasKey('target_column', $res['errors']);
        $this->assertCount(0, $this->admin->all(), 'illegal binding must not persist');
    }

    public function test_unregistered_entity_rejected(): void
    {
        $data = $this->legalData();
        $data['entity_type'] = 'bogus_entity'; // not in the registry
        $res = $this->admin->save($data);
        $this->assertArrayHasKey('entity_type', $res['errors'] ?? array());
    }

    public function test_per_entity_restricted_to_product(): void
    {
        $data = $this->legalData();
        $data['source_mode'] = 'per_entity';

        // product: per_entity is allowed (its form has the authoring tab).
        $data['entity_type'] = 'product';
        $this->assertArrayNotHasKey('source_mode', $this->admin->validate($data), 'per_entity allowed for product');

        // category/information: rejected until their forms get the tab + preload.
        foreach (array('category', 'information') as $type) {
            $data['entity_type'] = $type;
            $this->assertArrayHasKey('source_mode', $this->admin->validate($data), "per_entity rejected for {$type}");
        }
    }

    public function test_missing_template_rejected(): void
    {
        $data = $this->legalData();
        $data['template_id'] = 9999;
        $res = $this->admin->save($data);
        $this->assertArrayHasKey('template_id', $res['errors'] ?? array());
    }

    public function test_duplicate_cell_reported_cleanly(): void
    {
        $this->admin->save($this->legalData('meta_description'));
        // Same target+scope again → uniq_binding_target → clean error, not a crash.
        $res = $this->admin->save($this->legalData('meta_description'));
        $this->assertArrayHasKey('target_column', $res['errors'] ?? array());
        $this->assertCount(1, $this->admin->all());
    }

    public function test_update_existing_keeps_id(): void
    {
        $id = $this->admin->save($this->legalData())['binding_id'];
        $data = $this->legalData();
        $data['binding_id'] = $id;
        $data['status'] = 0;
        $res = $this->admin->save($data);
        $this->assertSame($id, $res['binding_id']);
        $this->assertSame('0', (string) $this->admin->find($id)['status']);
        $this->assertCount(1, $this->admin->all(), 'update must not create a second row');
    }

    public function test_delete_purges_own_walk_and_signatures(): void
    {
        $id = $this->admin->save($this->legalData())['binding_id'];
        $this->db->query("INSERT INTO `" . self::PREFIX . "spintax_walk` SET binding_id='{$id}', date_modified=NOW()");
        $this->db->query("INSERT INTO `" . self::PREFIX . "spintax_signature` SET binding_id='{$id}', entity_id=1, language_id=1, store_id=-1, signature='x', date_modified=NOW()");

        $this->admin->delete($id);

        $this->assertNull($this->admin->find($id));
        $this->assertSame(0, $this->db->query("SELECT COUNT(*) c FROM `" . self::PREFIX . "spintax_walk` WHERE binding_id='{$id}'")->row['c'] + 0);
        $this->assertSame(0, $this->db->query("SELECT COUNT(*) c FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id='{$id}'")->row['c'] + 0);
    }
}
