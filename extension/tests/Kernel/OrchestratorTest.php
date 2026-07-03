<?php
/**
 * Phase 0.5 gate — orchestrator byte-identity.
 *
 * Ported from the WP `RendererTest`: only the cases that exercise
 * `process_template()` directly (no WP posts / options / object cache) are
 * portable. Their expected values are the WordPress reference implementation's
 * — kept UNCHANGED, so passing here proves the OpenCart orchestrator reproduces
 * the WP pipeline byte-for-byte (pre-sanitize). The render()/cache/shortcode
 * cases stay in the WP suite (they depend on the four WP seams, replaced by OC
 * shims in Phase 0.6 / Phase 1).
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Orchestrator;

final class OrchestratorTest extends TestCase
{
    private Orchestrator $orch;

    protected function setUp(): void
    {
        // Deterministic parser (always min) for predictable output.
        $this->orch = new Orchestrator(new Parser(static fn(int $min, int $max): int => $min));
    }

    /**
     * Orchestrator whose parser returns a scripted RNG sequence (last value
     * reused once exhausted). Mirrors WP RendererTest::make_sequence_parser.
     *
     * @param int[] $sequence
     */
    private function make_sequence_orchestrator(array $sequence, int &$calls): Orchestrator
    {
        $index = 0;
        $calls = 0;
        $parser = new Parser(
            static function (int $min, int $max) use ($sequence, &$index, &$calls): int {
                ++$calls;
                $last_index = count($sequence) - 1;
                $value = $sequence[min($index, $last_index)] ?? $min;
                ++$index;
                return max($min, min($max, $value));
            }
        );
        return new Orchestrator($parser);
    }

    // --- process_template basics --------------------------------------------

    public function test_process_template_without_post(): void
    {
        $result = $this->orch->process_template("#set %x% = World\n{Hello|Hi} %x%!", array());
        $this->assertSame('Hello World!', $result);
    }

    public function test_process_template_rerandomises_on_each_generation_call(): void
    {
        $calls = 0;
        $orch = $this->make_sequence_orchestrator(array(0, 1), $calls);
        $template = "#set %greeting% = {hello|hi}\n%greeting%";

        $first = $orch->process_template($template);
        $second = $orch->process_template($template);

        $this->assertSame('Hello', $first);
        $this->assertSame('Hi', $second);
        $this->assertSame(2, $calls);
    }

    // --- {?VAR?then|else} conditionals (full pipeline) ----------------------

    public function test_conditional_truthy_runtime_variable(): void
    {
        $result = $this->orch->process_template('{?HasBonus?Claim bonus|Deposit}', array('HasBonus' => '1'));
        $this->assertSame('Claim bonus', $result);
    }

    public function test_conditional_falsy_runtime_variable(): void
    {
        $result = $this->orch->process_template('{?HasBonus?Claim bonus|Deposit}', array('HasBonus' => ''));
        $this->assertSame('Deposit', $result);
    }

    public function test_conditional_undefined_falls_to_else(): void
    {
        $result = $this->orch->process_template('{?HasBonus?Claim bonus|Deposit}', array());
        $this->assertSame('Deposit', $result);
    }

    public function test_conditional_inside_variable_value_resolves_truthy(): void
    {
        $result = $this->orch->process_template(
            "#set %CTA% = {?HasBonus?Claim bonus|Deposit}\n#set %HasBonus% = 1\n%CTA%",
            array()
        );
        $this->assertSame('Claim bonus', $result);
    }

    public function test_conditional_inside_variable_value_resolves_falsy(): void
    {
        $result = $this->orch->process_template(
            "#set %CTA% = {?HasBonus?Claim bonus|Deposit}\n#set %HasBonus% =\n%CTA%",
            array()
        );
        $this->assertSame('Deposit', $result);
    }

    public function test_conditional_truthy_keeps_nested_permutation(): void
    {
        $result = $this->orch->process_template(
            '{?A?[<sep=", ";minsize=3;maxsize=3>x|y|z]}',
            array('A' => '1')
        );
        $lowered = strtolower($result);
        $this->assertStringContainsString('x', $lowered);
        $this->assertStringContainsString('y', $lowered);
        $this->assertStringContainsString('z', $lowered);
        $this->assertStringNotContainsString('[', $result);
        $this->assertStringNotContainsString(']', $result);
    }

    public function test_conditional_falsy_skips_permutation_entirely(): void
    {
        $result = $this->orch->process_template(
            '{?A?[<sep=", ";minsize=3;maxsize=3>x|y|z]}',
            array('A' => '')
        );
        $this->assertSame('', $result);
    }

    public function test_set_inside_conditional_branch_extracted_unconditionally(): void
    {
        $result = $this->orch->process_template(
            "{?A?\n#set %x% = first\n|}{?A?\n#set %x% = second\n|}%x%",
            array('A' => '1')
        );
        $this->assertSame('Second', $result);
    }

    public function test_inverted_conditional_runs_when_var_absent(): void
    {
        $result = $this->orch->process_template('{?!Bonus?Open free demo|Claim bonus}', array());
        $this->assertSame('Open free demo', $result);
    }

    public function test_malformed_conditional_does_not_throw(): void
    {
        $result = $this->orch->process_template('{?VAR}', array());
        $this->assertIsString($result);
        $this->assertStringContainsString('VAR', $result);
    }

    // --- plural integration (OC-specific: locale drives arity) --------------

    public function test_post_process_flag_bypasses_prose_tail(): void
    {
        // §9.5: slug mode must skip the capitalising/re-spacing post_process tail.
        // Default (true) capitalises; false leaves the raw lowercase pick.
        $this->assertSame('Hello', $this->orch->process_template('{hello|world}'));
        $this->assertSame(
            'hello',
            $this->orch->process_template('{hello|world}', array(), null, '', null, false)
        );

        // Re-spacing + capitalisation are skipped: post_process turns "a ,b"
        // into "A, b" (space fixed, first letter capitalised); bypass leaves it raw.
        $this->assertSame(
            'a ,b',
            $this->orch->process_template('a ,b', array(), null, '', null, false)
        );
        $this->assertSame('A, b', $this->orch->process_template('a ,b'));
    }

    public function test_plural_ru_three_form_via_locale(): void
    {
        $tpl = '{plural 2: товар|товара|товаров}';
        $this->assertSame('Товара', $this->orch->process_template($tpl, array(), null, 'ru-RU'));
        $this->assertSame('Товаров', $this->orch->process_template('{plural 5: товар|товара|товаров}', array(), null, 'ru-RU'));
        $this->assertSame('Товар', $this->orch->process_template('{plural 1: товар|товара|товаров}', array(), null, 'ru-RU'));
    }
}
