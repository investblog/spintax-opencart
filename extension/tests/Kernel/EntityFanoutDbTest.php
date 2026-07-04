<?php
/**
 * Phase-2 entity fan-out: proves the descriptor-driven Applier seeds+preserves for
 * Category and Information exactly as for Product — on real stand rows, hitting
 * each entity's OWN base/description table, id column and name/title column
 * (information resolves %name% from `title`, not `name`). Snapshots + restores
 * every touched cell; self-skips off the dev stand.
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
use Spintax\Core\Binding\EntityType;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Schema;

final class EntityFanoutDbTest extends TestCase
{
    private const PREFIX = 'oc_';
    private const BINDING = 'bind_fan0ut';

    private MysqliDb $db;
    private LanguageResolver $langs;

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
        $this->clearSignatures();
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        $this->clearSignatures();
        $this->db->link()->close();
    }

    private function clearSignatures(): void
    {
        $this->db->query("DELETE FROM `" . self::PREFIX . "spintax_signature` WHERE binding_id = '" . self::BINDING . "'");
    }

    public function test_category_seed_then_preserve(): void
    {
        $this->assertSeedThenPreserve(EntityRegistry::get('category'));
    }

    public function test_information_seed_then_preserve(): void
    {
        // Information's display field is `title`; %name% must resolve from it.
        $this->assertSeedThenPreserve(EntityRegistry::get('information'));
    }

    private function assertSeedThenPreserve(EntityType $entity): void
    {
        $langIds = array_keys($this->langs->activeLanguages());
        $this->assertGreaterThanOrEqual(2, count($langIds), 'stand must have >=2 active languages');

        $entityId = $this->firstEntityWithAllLangs($entity, $langIds);
        $this->assertGreaterThan(0, $entityId, "no {$entity->type} with rows in every active language on the stand");

        $col = 'meta_description';
        $snapshot = array();
        foreach ($langIds as $l) {
            $snapshot[$l] = $this->readCell($entity, $entityId, $l, $col);
        }

        try {
            foreach ($langIds as $l) {
                $this->writeCell($entity, $entityId, $l, $col, '');
            }
            $this->clearSignatures();

            $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
            $applier = new Applier($this->db, self::PREFIX, $engine, $this->langs);
            $binding = new EntityBinding(self::BINDING, $entity, 'description_column', $col);
            $source = '{Buy|Order} %name% now.';

            // 1) Seed-once: fills the empty cell in every language; %name% resolves
            //    from the entity's own name/title column.
            $seed = $applier->applyTo($entityId, $binding, $source);
            foreach ($langIds as $l) {
                $this->assertSame(PlanCode::WROTE_SEEDED, $seed[$l], "{$entity->type} should seed lang {$l}");
                $name = $this->readName($entity, $entityId, $l);
                $expected = $engine->renderPlain($source, array('name' => $name), $this->langs->activeLanguages()[$l]);
                $this->assertNotSame('', $name, "sample {$entity->type} must have a name/title in lang {$l}");
                $this->assertSame($expected, $this->readCell($entity, $entityId, $l, $col), "seeded {$entity->type} value lang {$l}");
                $this->assertStringContainsString($name, $this->readCell($entity, $entityId, $l, $col), 'seeded value embeds the resolved %name%');
            }

            // 2) Preserve: re-run leaves the seeded cell untouched.
            $again = $applier->applyTo($entityId, $binding, $source);
            foreach ($langIds as $l) {
                $this->assertSame(PlanCode::SKIP_TARGET_NONEMPTY, $again[$l], "{$entity->type} preserve lang {$l}");
            }
        } finally {
            foreach ($langIds as $l) {
                $this->writeCell($entity, $entityId, $l, $col, $snapshot[$l]);
            }
        }
    }

    /** First entity id that has a description row in every active language. */
    private function firstEntityWithAllLangs(EntityType $entity, array $langIds): int
    {
        $rows = $this->db->query(
            "SELECT `{$entity->idColumn}` AS id FROM `" . self::PREFIX . "{$entity->baseTable}` ORDER BY `{$entity->idColumn}`"
        )->rows;
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $complete = true;
            foreach ($langIds as $l) {
                $c = (int) $this->db->query(
                    "SELECT COUNT(*) AS c FROM `" . self::PREFIX . "{$entity->descriptionTable}` "
                    . "WHERE `{$entity->idColumn}` = {$id} AND language_id = {$l}"
                )->row['c'];
                if ($c < 1) {
                    $complete = false;
                    break;
                }
            }
            if ($complete) {
                return $id;
            }
        }
        return 0;
    }

    private function readCell(EntityType $entity, int $id, int $lang, string $col): string
    {
        $q = $this->db->query(
            "SELECT `{$col}` AS v FROM `" . self::PREFIX . "{$entity->descriptionTable}` "
            . "WHERE `{$entity->idColumn}` = {$id} AND language_id = {$lang}"
        );
        return (string) ($q->row['v'] ?? '');
    }

    private function readName(EntityType $entity, int $id, int $lang): string
    {
        $q = $this->db->query(
            "SELECT `{$entity->nameColumn}` AS n FROM `" . self::PREFIX . "{$entity->descriptionTable}` "
            . "WHERE `{$entity->idColumn}` = {$id} AND language_id = {$lang}"
        );
        return (string) ($q->row['n'] ?? '');
    }

    private function writeCell(EntityType $entity, int $id, int $lang, string $col, string $val): void
    {
        $this->db->query(
            "UPDATE `" . self::PREFIX . "{$entity->descriptionTable}` SET `{$col}` = '" . $this->db->escape($val) . "' "
            . "WHERE `{$entity->idColumn}` = {$id} AND language_id = {$lang}"
        );
    }
}
