<?php
/**
 * Phase 0.5 — orchestrator regression lock on the real 47 KB production template.
 *
 * `review-casino.txt` is the real WP-plugin production template (an INPUT).
 * Rendered through the OC orchestrator with a deterministic always-min RNG it
 * yields a fixed pre-sanitize output, committed as
 * `orchestrator-golden-min-rng.txt`. This is a **regression lock** on the
 * orchestrator glue — not a cross-kernel proof (that is PortIntegrityTest +
 * the ported corpus). It guards against any future change silently altering the
 * full-pipeline output on a large real-world template.
 *
 * NOTE: `rendered-output.txt` in the same fixtures dir is a *random* one-off
 * sample (WP `render-preview.php`, real RNG) — deliberately NOT used as a golden.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Orchestrator;

final class OrchestratorGoldenTest extends TestCase
{
    private function render_min_rng(): string
    {
        $parser = new Parser(static fn(int $min, int $max): int => $min);
        $orch = new Orchestrator($parser);
        $input = (string) file_get_contents(spintax_fixture('review-casino.txt'));
        // Normalize CRLF/CR -> LF so the render is byte-deterministic regardless of
        // how git checked the fixture out (Windows dev vs Linux CI).
        $input = str_replace(array("\r\n", "\r"), "\n", $input);
        return $orch->process_template($input, array(), null, 'ru-RU');
    }

    public function test_matches_committed_golden(): void
    {
        $golden = (string) file_get_contents(spintax_fixture('orchestrator-golden-min-rng.txt'));
        $golden = str_replace(array("\r\n", "\r"), "\n", $golden);
        $this->assertSame($golden, $this->render_min_rng(), 'orchestrator output drifted from the committed golden');
    }

    public function test_render_is_deterministic(): void
    {
        $this->assertSame($this->render_min_rng(), $this->render_min_rng());
    }

    public function test_no_unresolved_spintax_syntax_leaks(): void
    {
        $out = $this->render_min_rng();
        $this->assertStringNotContainsString('{', $out, 'unresolved enumeration/conditional/plural brace leaked');
        $this->assertStringNotContainsString('[', $out, 'unresolved permutation bracket leaked');
        $this->assertStringNotContainsString('#set ', $out, '#set directive leaked into output');
        $this->assertNotEmpty($out);
    }
}
