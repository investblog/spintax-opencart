<?php
/**
 * Phase 1.4 gate (live DB) — the bulk Walk (§7): dry-run counts + snapshot token,
 * chunked apply to completion with the zero-failure version stamp, and
 * STALE_SNAPSHOT rejection when config changes between Dry run and Apply.
 * Snapshots + restores every product's meta_description; SKIPS off the stand.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\LanguageResolver;
use Spintax\Core\Binding\Applier;
use Spintax\Core\Binding\Walk;
use Spintax\Core\Engine\Parser;
use Spintax\Db\MysqliDb;
use Spintax\Engine;
use Spintax\Install\Installer;

final class WalkDbTest extends TestCase
{
    private const PREFIX = 'oc_';

    private MysqliDb $db;
    private LanguageResolver $langs;
    private Walk $walk;
    private string $source;
    /** @var array<string, string> "pid:lang" => meta_description */
    private array $snapshot = array();

    protected function setUp(): void
    {
        try {
            $this->db = MysqliDb::connect('db', 'opencart', 'opencart', 'opencart');
        } catch (\RuntimeException $e) {
            $this->markTestSkipped('live db not reachable: ' . $e->getMessage());
        }

        (new Installer($this->db, self::PREFIX))->install(1);
        $this->db->query("UPDATE `" . self::PREFIX . "spintax_binding` SET status = 1 WHERE binding_id = 'bind_demo01'");
        $this->source = (string) $this->db->query("SELECT source FROM `" . self::PREFIX . "spintax_template` LIMIT 1")->row['source'];

        $this->langs = new LanguageResolver($this->db, self::PREFIX);
        $engine = new Engine(new Parser(static fn(int $min, int $max): int => $min));
        $applier = new Applier($this->db, self::PREFIX, $engine, $this->langs);
        $this->walk = new Walk($this->db, self::PREFIX, $applier, $this->langs);

        // Snapshot + empty every product's meta_description so seeds apply.
        $rows = $this->db->query(
            "SELECT product_id, language_id, meta_description FROM `" . self::PREFIX . "product_description`"
        )->rows;
        foreach ($rows as $r) {
            $this->snapshot["{$r['product_id']}:{$r['language_id']}"] = (string) $r['meta_description'];
        }
        $this->db->query("UPDATE `" . self::PREFIX . "product_description` SET meta_description = ''");
    }

    protected function tearDown(): void
    {
        if (!isset($this->db)) {
            return;
        }
        foreach ($this->snapshot as $key => $val) {
            [$pid, $lang] = explode(':', $key);
            $this->db->query(
                "UPDATE `" . self::PREFIX . "product_description` SET meta_description = '" . $this->db->escape($val) . "' "
                . "WHERE product_id = " . (int) $pid . " AND language_id = " . (int) $lang
            );
        }
        (new Installer($this->db, self::PREFIX))->uninstall(true);
        $this->db->link()->close();
    }

    private function bindingRow(): array
    {
        return $this->db->query(
            "SELECT * FROM `" . self::PREFIX . "spintax_binding` WHERE binding_id = 'bind_demo01'"
        )->row;
    }

    private function productCount(): int
    {
        return (int) $this->db->query("SELECT COUNT(*) AS c FROM `" . self::PREFIX . "product`")->row['c'];
    }

    private function nonEmptyMetaCount(): int
    {
        return (int) $this->db->query(
            "SELECT COUNT(*) AS c FROM `" . self::PREFIX . "product_description` WHERE meta_description <> ''"
        )->row['c'];
    }

    public function test_dry_run_counts_without_writing(): void
    {
        $langCount = count($this->langs->activeLanguages());
        $expectedCells = $this->productCount() * $langCount;

        $result = $this->walk->dryRun($this->bindingRow(), $this->source);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $result['dry_run_token']);
        $this->assertSame($this->productCount(), $result['entities']);
        $this->assertSame($expectedCells, $result['total']);
        $this->assertSame($expectedCells, $result['write'], 'all empty targets should be seed-writes');
        $this->assertSame(0, $result['skip']);
        $this->assertSame(0, $result['blocked']);

        // Dry run must NOT have written anything.
        $this->assertSame(0, $this->nonEmptyMetaCount(), 'dry run must not write to the catalog');

        // Walk row carries the token + reset cursor.
        $walk = $this->walk->loadWalk('bind_demo01');
        $this->assertSame($result['dry_run_token'], $walk['snapshot_token']);
        $this->assertSame('0', (string) $walk['cursor_offset']);
    }

    public function test_apply_chunks_to_completion_and_stamps_version(): void
    {
        $row = $this->bindingRow();
        $langCount = count($this->langs->activeLanguages());
        $expectedCells = $this->productCount() * $langCount;

        $dry = $this->walk->dryRun($row, $this->source);
        $token = $dry['dry_run_token'];

        $written = 0;
        $guard = 0;
        $lockTs = null;
        do {
            $r = $this->walk->applyChunk($row, $this->source, $token, 5, $lockTs);
            $this->assertArrayNotHasKey('error', $r, 'no error mid-walk');
            $lockTs = $r['lock_ts']; // carry ownership to the next chunk
            $written += $r['written'];
        } while (!$r['done'] && ++$guard < 100);

        $this->assertTrue($r['done']);
        $this->assertSame($this->productCount(), $r['processed']);
        $this->assertSame($expectedCells, $written, 'every cell seeded exactly once');
        $this->assertSame($expectedCells, $this->nonEmptyMetaCount(), 'all meta populated');

        $walk = $this->walk->loadWalk('bind_demo01');
        $this->assertSame((string) $row['cache_version'], (string) $walk['last_applied_version'], 'zero-failure walk stamps the version');
        $this->assertSame('0', (string) $walk['lock_ts'], 'lock released on completion');
    }

    public function test_stale_token_rejected_after_config_change(): void
    {
        $dry = $this->walk->dryRun($this->bindingRow(), $this->source);
        $token = $dry['dry_run_token'];

        // Simulate a binding edit between Dry run and Apply.
        $this->db->query("UPDATE `" . self::PREFIX . "spintax_binding` SET cache_version = cache_version + 1 WHERE binding_id = 'bind_demo01'");

        $r = $this->walk->applyChunk($this->bindingRow(), $this->source, $token, 5);
        $this->assertSame('STALE_SNAPSHOT', $r['error'] ?? '');
        $this->assertSame(0, $this->nonEmptyMetaCount(), 'stale apply writes nothing');
    }

    public function test_apply_without_dry_run_is_rejected(): void
    {
        // No dry run → no walk row → NO_DRY_RUN.
        $r = $this->walk->applyChunk($this->bindingRow(), $this->source, 'deadbeef', 5);
        $this->assertSame('NO_DRY_RUN', $r['error'] ?? '');
    }

    public function test_walk_lock_refuses_second_apply_but_lets_owner_continue(): void
    {
        $row = $this->bindingRow();
        $token = $this->walk->dryRun($row, $this->source)['dry_run_token'];

        // Owner acquires the lock on the first chunk.
        $first = $this->walk->applyChunk($row, $this->source, $token, 5, null);
        $this->assertArrayNotHasKey('error', $first);
        $lockTs = $first['lock_ts'];
        $this->assertGreaterThan(0, $lockTs, 'owner holds a live lock');

        // A second Apply that does NOT own the lock is refused (no lock_ts echo).
        $intruder = $this->walk->applyChunk($row, $this->source, $token, 5, null);
        $this->assertSame('WALK_LOCKED', $intruder['error'] ?? '');
        $this->assertArrayNotHasKey('lock_ts', $intruder, 'refusal must not leak the lock_ts');

        // The owner continues by echoing its lock_ts.
        $second = $this->walk->applyChunk($row, $this->source, $token, 5, $lockTs);
        $this->assertArrayNotHasKey('error', $second);
    }

    public function test_release_lock_refuses_live_lock_but_clears_stale(): void
    {
        $row = $this->bindingRow();
        $token = $this->walk->dryRun($row, $this->source)['dry_run_token'];
        $this->walk->applyChunk($row, $this->source, $token, 5, null); // acquire live lock

        // Force-release refuses a live lock.
        $this->assertSame('LOCK_ACTIVE', $this->walk->releaseLock('bind_demo01')['error'] ?? '');

        // Make it stale, then force-release succeeds.
        $this->db->query("UPDATE `" . self::PREFIX . "spintax_walk` SET lock_ts = 1 WHERE binding_id = 'bind_demo01'");
        $this->assertTrue($this->walk->releaseLock('bind_demo01')['success'] ?? false);
        $this->assertSame('0', (string) $this->walk->loadWalk('bind_demo01')['lock_ts']);
    }

    public function test_disabled_binding_is_rejected_by_bulk(): void
    {
        $this->db->query("UPDATE `" . self::PREFIX . "spintax_binding` SET status = 0 WHERE binding_id = 'bind_demo01'");
        $row = $this->bindingRow();

        $this->assertSame('BINDING_DISABLED', $this->walk->dryRun($row, $this->source)['error'] ?? '');
        $this->assertSame('BINDING_DISABLED', $this->walk->applyChunk($row, $this->source, 'x', 5)['error'] ?? '');
        $this->assertSame(0, $this->nonEmptyMetaCount(), 'disabled binding writes nothing');
    }
}
