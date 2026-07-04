<?php
/**
 * #include nesting (spec §9.3) — the OC-layer recursive resolver with cycle +
 * depth guards. Pure (array-backed template lookup, deterministic min-RNG); no DB.
 * `#include "name"` must be on its own line (the kernel regex is `^…$`).
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Template\IncludeResolver;
use Spintax\Engine;

final class IncludeResolverTest extends TestCase
{
    /** @param array<string, string> $templates name => source */
    private function render(string $source, array $templates, array $vars = array()): string
    {
        $engine = new Engine(new Parser(static fn(int $a, int $b): int => $a));
        $lookup = static fn(string $n): ?string => $templates[$n] ?? null;
        $resolver = IncludeResolver::build($engine, $lookup, $vars, '');
        return $engine->renderPlain($source, $vars, '', $resolver);
    }

    public function test_basic_include_is_spliced_in(): void
    {
        $out = $this->render("Intro.\n#include \"foot\"", array('foot' => 'FOOTERMARK'));
        $this->assertStringContainsString('FOOTERMARK', $out);
    }

    public function test_nested_include_resolves(): void
    {
        $out = $this->render('#include "a"', array('a' => "A\n#include \"b\"", 'b' => 'INNERMARK'));
        $this->assertStringContainsString('INNERMARK', $out);
    }

    public function test_spintax_inside_include_resolves(): void
    {
        // min-RNG picks the first alternative; post_process may recapitalise.
        $out = $this->render('#include "x"', array('x' => '{alphaMark|betaMark}'));
        $this->assertStringContainsStringIgnoringCase('alphaMark', $out);
        $this->assertStringNotContainsString('betaMark', $out);
    }

    public function test_missing_include_is_empty_not_fatal(): void
    {
        $out = $this->render("startMark\n#include \"nope\"", array());
        $this->assertStringContainsStringIgnoringCase('startMark', $out); // post_process may recapitalise
        $this->assertStringNotContainsString('nope', $out);
    }

    public function test_direct_cycle_terminates(): void
    {
        $out = $this->render('#include "a"', array('a' => "LOOPMARK\n#include \"a\""));
        $this->assertStringContainsString('LOOPMARK', $out); // one level renders; self-recursion stops
    }

    public function test_mutual_cycle_terminates(): void
    {
        $out = $this->render('#include "a"', array('a' => "AMARK\n#include \"b\"", 'b' => "BMARK\n#include \"a\""));
        $this->assertStringContainsString('AMARK', $out);
        $this->assertStringContainsString('BMARK', $out);
    }

    public function test_repeated_include_memoizes_the_lookup(): void
    {
        $calls = array();
        $engine = new Engine(new Parser(static fn(int $a, int $b): int => $a));
        $templates = array('a' => "#include \"leaf\"\n#include \"leaf\"\n#include \"leaf\"", 'leaf' => 'LEAFMARK');
        $lookup = static function (string $n) use (&$calls, $templates): ?string {
            $calls[$n] = ($calls[$n] ?? 0) + 1;
            return $templates[$n] ?? null;
        };
        $resolver = IncludeResolver::build($engine, $lookup, array(), '');
        $out = $engine->renderPlain('#include "a"', array(), '', $resolver);

        $this->assertSame(3, substr_count($out, 'LEAFMARK'), 'leaf spliced in three times');
        $this->assertSame(1, $calls['leaf'], 'but looked up only once (memoized)');
    }

    public function test_fanout_bomb_is_bounded_by_budget(): void
    {
        // Each level includes the next FIVE times: 5^4 ≈ 781 renders without a
        // budget (a billion-laughs). The shared budget caps total expansion.
        $engine = new Engine(new Parser(static fn(int $a, int $b): int => $a));
        $t = array();
        for ($i = 0; $i < 5; ++$i) {
            $t["t{$i}"] = "ZZZ\n" . str_repeat("#include \"t" . ($i + 1) . "\"\n", 5);
        }
        $resolver = IncludeResolver::build($engine, static fn(string $n): ?string => $t[$n] ?? null, array(), '', 5, 40);
        $out = $engine->renderPlain('#include "t0"', array(), '', $resolver);

        $this->assertGreaterThan(0, substr_count($out, 'ZZZ'));
        $this->assertLessThanOrEqual(40, substr_count($out, 'ZZZ'), 'total expansion capped by the budget');
    }

    public function test_depth_cap_truncates_gracefully(): void
    {
        $t = array();
        for ($i = 0; $i < 12; ++$i) {
            $t["t{$i}"] = "L{$i}\n#include \"t" . ($i + 1) . "\"";
        }
        $out = $this->render('#include "t0"', $t);
        $this->assertStringContainsString('L0', $out);
        // MAX_DEPTH=5: t0..t4 render (depths 1..5), t5 (depth 6) is cut.
        $this->assertStringContainsString('L4', $out);
        $this->assertStringNotContainsString('L5', $out);
    }

    public function test_include_resolves_in_slug_mode(): void
    {
        // seo_keyword renders via Engine::renderSlug — #include must resolve there
        // too, else the slug is built from the literal directive, not the partial.
        $engine = new Engine(new Parser(static fn(int $a, int $b): int => $a));
        $resolver = IncludeResolver::build($engine, static fn(string $n): ?string => 'city' === $n ? 'Berlin' : null, array(), '');

        $with = $engine->renderSlug("#include \"city\"\nhotel", array(), '', 255, $resolver);
        $this->assertStringContainsString('berlin', $with, 'the include is resolved before slugifying');
        $this->assertStringContainsString('hotel', $with);

        $without = $engine->renderSlug("#include \"city\"\nhotel", array(), '', 255);
        $this->assertStringNotContainsString('berlin', $without, 'without a resolver the partial cannot appear');
    }
}
