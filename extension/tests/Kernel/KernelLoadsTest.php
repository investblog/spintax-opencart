<?php
/**
 * Phase 0.1 + 0.2 gate: the six kernel files are in place at their PSR-4 path,
 * load cleanly, and the runtime SPL autoloader resolves them without Composer.
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
    private const LIB = __DIR__ . '/../../upload/system/library/spintax';

    private const KERNEL_FILES = [
        'Core/Engine/Parser.php',
        'Core/Engine/Conditionals.php',
        'Core/Engine/Plurals.php',
        'Core/Engine/PluralArityError.php',
        'Core/Engine/PluralFormError.php',
        'Core/Render/RenderContext.php',
    ];

    public function test_all_six_kernel_files_exist_at_psr4_path(): void
    {
        foreach (self::KERNEL_FILES as $rel) {
            $this->assertFileExists(self::LIB . '/' . $rel, "kernel file {$rel} must be ported");
        }
    }

    /** @dataProvider kernelClasses */
    public function test_kernel_class_loads_from_ported_location(string $class): void
    {
        $this->assertTrue(class_exists($class), "{$class} must autoload");
        $file = (new \ReflectionClass($class))->getFileName();
        $this->assertStringContainsString(
            'system' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'spintax',
            $file,
            "{$class} must resolve to the ported OpenCart library, not the WP source"
        );
    }

    public static function kernelClasses(): array
    {
        return [
            [Parser::class],
            [Conditionals::class],
            [Plurals::class],
            [RenderContext::class],
            [\Spintax\Core\Engine\PluralArityError::class],
            [\Spintax\Core\Engine\PluralFormError::class],
        ];
    }

    public function test_no_wordpress_symbols_survive_in_kernel(): void
    {
        foreach (self::KERNEL_FILES as $rel) {
            $src = file_get_contents(self::LIB . '/' . $rel);
            foreach (['ABSPATH', 'wp_json_encode', 'get_option', 'wp_kses'] as $needle) {
                $this->assertStringNotContainsString($needle, $src, "{$needle} must not survive in {$rel}");
            }
        }
    }

    public function test_runtime_autoloader_resolves_without_composer(): void
    {
        // Prove autoload.php works at OpenCart runtime (no Composer present) by
        // running it in a fresh PHP process that never sees vendor/autoload.php.
        $script = self::LIB . '/autoload.php';
        $code = <<<'PHP'
require $argv[1];
$p = new Spintax\Core\Engine\Parser(fn(int $a, int $b) => $a);
echo $p->process('{alpha|beta}');
PHP;
        $cmd = escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code)
            . ' ' . escapeshellarg($script);
        $out = shell_exec($cmd);
        // min-RNG picks the first option; post_process capitalises the first letter.
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
