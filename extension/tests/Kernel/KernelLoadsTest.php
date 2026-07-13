<?php
/**
 * The engine is a pinned dependency now — this is what enforces it.
 *
 * OpenCart has no Composer at run time: the OCMOD ships `upload/` and nothing else, and the engine
 * is found by the extension's own PSR-4 autoloader. So the engine cannot merely be a `vendor/`
 * entry — it has to physically live in the tree that gets zipped. `composer run sync-kernel`
 * unpacks the pinned `spintax/core` into that tree, and the tests below make a stale copy a red
 * test instead of a silent divergence.
 *
 * That distinction is the whole reason this file changed. Its predecessor, `PortIntegrityTest`,
 * compared two copies that both lived *inside this repository* — and it stayed green while the
 * kernel drifted two commits behind the WordPress engine, because both copies were stale together.
 * A checksum against a second local copy proves consistency, never freshness. Freshness comes from
 * comparing against something external and versioned, which is what the pin is.
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Conditionals;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Plurals;
use Spintax\Core\Render\RenderContext;

final class KernelLoadsTest extends TestCase
{
    /** The runtime tree: what the OCMOD ships and what OpenCart autoloads. */
    private const RUNTIME = __DIR__ . '/../../upload/system/library/spintax/Core';

    /** The pinned package: the source of truth. */
    private const PACKAGE = __DIR__ . '/../../vendor/spintax/core/src';

    /** @return string[] Every engine file the pinned package ships, relative to its src/. */
    private static function packageFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PACKAGE, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::PACKAGE) + 1));
            }
        }

        sort($files);
        return $files;
    }

    public function test_the_pinned_package_is_installed(): void
    {
        $this->assertDirectoryExists(
            self::PACKAGE,
            "spintax/core is not installed — the engine is a dependency now.\n  cd extension && composer install"
        );
        $this->assertNotEmpty(self::packageFiles(), 'the pinned package ships no engine files');
    }

    /**
     * The gate: the tree OpenCart actually runs must be the pinned engine, byte for byte. Not "a
     * copy that looks right" — the same bytes as the version in composer.lock.
     */
    public function test_the_runtime_tree_is_the_pinned_engine_byte_for_byte(): void
    {
        $fix = 'Run `composer run sync-kernel` and commit the result.';

        foreach (self::packageFiles() as $relative) {
            $runtime = self::RUNTIME . '/' . $relative;

            $this->assertFileExists($runtime, "the runtime tree is missing Core/{$relative}. {$fix}");
            $this->assertSame(
                file_get_contents(self::PACKAGE . '/' . $relative),
                file_get_contents($runtime),
                "Core/{$relative} has drifted from the pinned spintax/core. {$fix}"
            );
        }
    }

    /**
     * The harness resolves the engine from the package, not from the runtime copies — that is what
     * `exclude-from-classmap` in composer.json is for. Asserting it keeps the two roles honest: the
     * package is the source, the runtime tree is its unpacked shipping form.
     */
    public function test_the_harness_loads_the_engine_from_the_pinned_package(): void
    {
        foreach ([Parser::class, Conditionals::class, Plurals::class, RenderContext::class] as $class) {
            $this->assertTrue(class_exists($class), "{$class} must autoload");

            $file = str_replace('\\', '/', (string) (new \ReflectionClass($class))->getFileName());
            $this->assertStringContainsString(
                'vendor/spintax/core/src',
                $file,
                "{$class} should come from the pinned package in this harness, not from the runtime copy"
            );
        }
    }

    public function test_no_wordpress_symbols_survive_in_the_runtime_kernel(): void
    {
        foreach (self::packageFiles() as $relative) {
            $src = (string) file_get_contents(self::RUNTIME . '/' . $relative);

            foreach (['ABSPATH', 'wp_json_encode', 'get_option', 'wp_kses'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$needle} must not survive in {$relative}");
            }
        }
    }

    public function test_runtime_autoloader_resolves_without_composer(): void
    {
        // The real proof: a fresh PHP process that never sees vendor/autoload.php, loading the
        // engine exactly the way OpenCart does — through the extension's own SPL autoloader over
        // the shipped tree. If the sync were broken, this is what would catch it in production.
        $script = self::RUNTIME . '/../autoload.php';
        $code = <<<'PHP'
require $argv[1];
$p = new Spintax\Core\Engine\Parser(fn(int $a, int $b) => $a);
echo $p->process('{alpha|beta}');
PHP;

        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' ' . escapeshellarg($script);
        $out = shell_exec($cmd);

        // Min-RNG picks the first option; post_process capitalises the first letter.
        $this->assertSame('Alpha', trim((string) $out));
    }

    public function test_kernel_smoke_behaviour(): void
    {
        $first = new Parser(static fn(int $min, int $max): int => $min);
        $this->assertSame('Alpha', $first->process('{alpha|beta|gamma}'));

        $cond = new Conditionals();
        $this->assertSame('yes', $cond->apply('{?X?yes|no}', ['X' => '1']));
        $this->assertSame('no', $cond->apply('{?X?yes|no}', ['X' => '']));

        $plurals = new Plurals();
        $this->assertSame('штуки', $plurals->apply('{plural 2: штука|штуки|штук}', 'ru-RU'));
        $this->assertSame('штук', $plurals->apply('{plural 5: штука|штуки|штук}', 'ru-RU'));

        $ctx = new RenderContext();
        $this->assertSame('default', $ctx->get_context_hash());
    }
}
