<?php
/**
 * Phase 0.4 gate: input cleaning (sanitize_spintax) port.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Support\InputSanitizer;

final class InputSanitizerTest extends TestCase
{
    public function test_strips_null_bytes_and_control_chars(): void
    {
        $raw = "a\x00b\x01c\x1fd\x7fe";
        $this->assertSame('abcde', InputSanitizer::sanitize_spintax($raw));
    }

    public function test_preserves_newlines_and_tabs(): void
    {
        $raw = "line1\nline2\tcol";
        $this->assertSame("line1\nline2\tcol", InputSanitizer::sanitize_spintax($raw));
    }

    public function test_normalizes_line_endings(): void
    {
        $this->assertSame("a\nb\nc", InputSanitizer::sanitize_spintax("a\r\nb\rc"));
    }

    public function test_preserves_valid_utf8_cyrillic(): void
    {
        $raw = 'Купить телефон в Москве';
        $this->assertSame($raw, InputSanitizer::sanitize_spintax($raw));
    }

    public function test_preserves_spintax_permutation_syntax(): void
    {
        // The whole reason a plain strip-tags sanitiser can't be used.
        $raw = '[<minsize=2;sep=", ";lastsep=" and "> apples|oranges|bananas]';
        $this->assertSame($raw, InputSanitizer::sanitize_spintax($raw));
    }

    public function test_strips_invalid_utf8_sequences(): void
    {
        // Lone 0xFF is not valid UTF-8; it must not survive, and valid text stays.
        $raw = "valid\xFFtext";
        $out = InputSanitizer::sanitize_spintax($raw);
        $this->assertStringNotContainsString("\xFF", $out);
        $this->assertStringContainsString('valid', $out);
        $this->assertStringContainsString('text', $out);
        $this->assertTrue(mb_check_encoding($out, 'UTF-8'), 'output must be valid UTF-8');
    }

    /** @dataProvider clampCases */
    public function test_clamp_int(int $value, int $min, int $max, int $expected): void
    {
        $this->assertSame($expected, InputSanitizer::clamp_int($value, $min, $max));
    }

    public static function clampCases(): array
    {
        return [
            'below' => [-5, 0, 100, 0],
            'above' => [500, 0, 100, 100],
            'within' => [42, 0, 100, 42],
            'at-min' => [0, 0, 100, 0],
            'at-max' => [100, 0, 100, 100],
        ];
    }

    public function test_normalize_global_variables(): void
    {
        $raw = ['%City%' => 'Moscow', '  Brand ' => '301', '' => 'skip'];
        $this->assertSame(
            ['city' => 'Moscow', 'brand' => '301'],
            InputSanitizer::normalize_global_variables($raw)
        );
    }

    public function test_normalize_global_variables_non_array_yields_empty(): void
    {
        $this->assertSame([], InputSanitizer::normalize_global_variables('nope'));
        $this->assertSame([], InputSanitizer::normalize_global_variables(null));
    }
}
