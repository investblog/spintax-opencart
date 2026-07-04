<?php
/**
 * Phase 1.0b gate (pure) — the 5-table DDL is well-formed and prefix-safe.
 * Runs everywhere (no DB). The live execution check is SchemaDbTest.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Install\Schema;

final class SchemaTest extends TestCase
{
    public function test_six_tables_named(): void
    {
        $this->assertCount(6, Schema::TABLES);
        $this->assertSame(
            array('spintax_binding', 'spintax_template', 'spintax_source', 'spintax_signature', 'spintax_walk', 'spintax_log'),
            Schema::TABLES
        );
    }

    public function test_table_names_are_prefixed(): void
    {
        $this->assertSame(
            array('oc_spintax_binding', 'oc_spintax_template', 'oc_spintax_source', 'oc_spintax_signature', 'oc_spintax_walk', 'oc_spintax_log'),
            Schema::tableNames('oc_')
        );
    }

    public function test_create_statements_substitute_prefix_and_never_hardcode_oc(): void
    {
        $stmts = Schema::createStatements('xyz_');
        $this->assertCount(6, $stmts);
        foreach ($stmts as $sql) {
            $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `xyz_spintax_', $sql);
            $this->assertStringContainsString('ENGINE=InnoDB', $sql);
            // With a non-oc prefix, no stray `oc_` table reference must survive.
            $this->assertStringNotContainsString('`oc_spintax', $sql);
        }
    }

    public function test_binding_table_has_pk_and_unique_target(): void
    {
        $sql = Schema::createStatements('oc_')[0];
        $this->assertStringContainsString('PRIMARY KEY (`binding_id`)', $sql);
        $this->assertStringContainsString('UNIQUE KEY `uniq_binding_target`', $sql);
    }

    public function test_walk_table_has_snapshot_token(): void
    {
        // Dry-run snapshot token column (spec §4.4 / §7.1) — keeps code in sync
        // with the UI contract in docs/ui-phase1.md.
        $walk = Schema::createStatements('oc_')[4];
        $this->assertMatchesRegularExpression('/`snapshot_token`\s+char\(40\)/', $walk);
    }

    public function test_drop_statements_reverse_order(): void
    {
        $drops = Schema::dropStatements('oc_');
        $this->assertCount(6, $drops);
        // Reverse create order: log first, binding last.
        $this->assertStringContainsString('DROP TABLE IF EXISTS `oc_spintax_log`', $drops[0]);
        $this->assertStringContainsString('DROP TABLE IF EXISTS `oc_spintax_binding`', $drops[5]);
    }
}
