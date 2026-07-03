<?php
/**
 * Proves the test harness itself runs green inside the dev container, before any
 * kernel code is ported. Deleted or superseded once Phase 0 lands real kernel tests.
 */

declare(strict_types=1);

namespace Spintax\Tests\Smoke;

use PHPUnit\Framework\TestCase;

final class HarnessSmokeTest extends TestCase
{
    public function test_php_version_meets_opencart_floor(): void
    {
        // OpenCart 3.0.5.0 hard-exits below PHP 8.1 (system/startup.php).
        $this->assertTrue(
            PHP_VERSION_ID >= 80100,
            'Harness must run on the same PHP 8.1+ the extension targets; got ' . PHP_VERSION
        );
    }

    public function test_engine_runtime_extensions_present(): void
    {
        // The ported kernel relies on mbstring; the slug adapter (§9.5) on iconv.
        $this->assertTrue(extension_loaded('mbstring'), 'mbstring required by the spintax engine');
        $this->assertTrue(extension_loaded('iconv'), 'iconv required by the §9.5 slug transliteration');
    }

    public function test_golden_fixtures_are_available(): void
    {
        foreach (['rendered-output.txt', 'review-casino.txt'] as $fixture) {
            $this->assertFileExists(
                spintax_fixture($fixture),
                "Golden fixture {$fixture} (shared with the WP kernel) must be present for the Phase 0 byte-identity gate"
            );
        }
    }
}
