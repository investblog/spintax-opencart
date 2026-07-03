<?php
/**
 * Phase 0.6 gate — OC Renderer wiring (cache reuse / ttl / sanitize).
 *
 * Ports the WP `RendererTest` render()-level cache cases (which depend on the
 * WP object cache) onto the OC RenderCache shim, preserving the WP-authored
 * expected values and RNG-call assertions.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Renderer;
use Spintax\Shim\ArrayRenderCache;
use Spintax\Shim\ArrayTemplateSourceProvider;

final class RendererCacheTest extends TestCase
{
    /** @param int[] $sequence */
    private function sequence_parser(array $sequence, int &$calls): Parser
    {
        $index = 0;
        $calls = 0;
        return new Parser(
            static function (int $min, int $max) use ($sequence, &$index, &$calls): int {
                ++$calls;
                $last = count($sequence) - 1;
                $value = $sequence[min($index, $last)] ?? $min;
                ++$index;
                return max($min, min($max, $value));
            }
        );
    }

    public function test_render_by_id(): void
    {
        $tpl = (new ArrayTemplateSourceProvider())->add(1, '{Hello|Hi} World');
        $r = new Renderer($tpl, new Parser(static fn(int $a, int $b): int => $a));
        $this->assertSame('Hello World', $r->render(1));
    }

    public function test_render_by_slug(): void
    {
        $tpl = (new ArrayTemplateSourceProvider())->add(7, 'Content here', 'my-template');
        $r = new Renderer($tpl, new Parser(static fn(int $a, int $b): int => $a));
        $this->assertSame('Content here', $r->render('my-template'));
    }

    public function test_render_nonexistent_returns_empty(): void
    {
        $r = new Renderer(new ArrayTemplateSourceProvider());
        $this->assertSame('', $r->render(99999));
        $this->assertSame('', $r->render('no-such-slug'));
    }

    public function test_runtime_variables_render(): void
    {
        $tpl = (new ArrayTemplateSourceProvider())->add(1, 'Hello %name%!');
        $r = new Renderer($tpl, new Parser(static fn(int $a, int $b): int => $a));
        $this->assertSame('Hello Alice!', $r->render(1, array('name' => 'Alice')));
    }

    public function test_default_cache_reuses_first_randomised_output(): void
    {
        $calls = 0;
        $parser = $this->sequence_parser(array(0, 1), $calls);
        $tpl = (new ArrayTemplateSourceProvider())->add(1, "#set %greeting% = {hello|hi}\n%greeting%", '', '', 3600);
        $r = new Renderer($tpl, $parser, new ArrayRenderCache());

        $first = $r->render(1);
        $second = $r->render(1);

        $this->assertSame('Hello', $first);
        $this->assertSame($first, $second);
        $this->assertSame(1, $calls, 'cached render must not re-invoke the RNG');
    }

    public function test_ttl_zero_rerenders_on_each_request(): void
    {
        $calls = 0;
        $parser = $this->sequence_parser(array(0, 1), $calls);
        $tpl = (new ArrayTemplateSourceProvider())->add(1, "#set %greeting% = {hello|hi}\n%greeting%", '', '', 0);
        $r = new Renderer($tpl, $parser, new ArrayRenderCache());

        $first = $r->render(1);
        $second = $r->render(1);

        $this->assertSame('Hello', $first);
        $this->assertSame('Hi', $second);
        $this->assertSame(2, $calls, 'ttl=0 must re-render (and re-invoke the RNG) each time');
    }

    public function test_output_is_sanitised(): void
    {
        $tpl = (new ArrayTemplateSourceProvider())->add(1, '<script>alert(1)</script><p>Safe</p>');
        $r = new Renderer($tpl, new Parser(static fn(int $a, int $b): int => $a));
        $out = $r->render(1);
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<p>Safe</p>', $out);
    }
}
