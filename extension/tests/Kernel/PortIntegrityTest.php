<?php
/**
 * Phase 0 byte-identity proof (source level).
 *
 * Asserts each ported kernel file is IDENTICAL to its WordPress source
 * (extension/reference/wp-kernel-src/) except for the exact documented edits
 * (spec §9.1):
 *   - the `defined('ABSPATH') || exit;` guard is removed;
 *   - WordPress phpcs pragma comments are removed;
 *   - `wp_json_encode` becomes `json_encode` (RenderContext only).
 *
 * This is the strongest achievable byte-identity check here (the WP kernel needs
 * the WP test harness to execute, but the *source* is directly comparable). It
 * catches any accidental logic change introduced during the copy. Combined with
 * the ported test corpus (ParserTest/…/OrchestratorTest passing WP-authored
 * expected values), it establishes engine parity before the sanitizer stage.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;

final class PortIntegrityTest extends TestCase
{
    private const OC_LIB = __DIR__ . '/../../upload/system/library/spintax';
    private const WP_SRC = __DIR__ . '/../../reference/wp-kernel-src';

    /** @dataProvider kernelFiles */
    public function test_ported_file_is_verbatim_modulo_documented_edits(string $rel): void
    {
        $oc = self::normalize((string) file_get_contents(self::OC_LIB . '/' . $rel));
        $wp = self::normalize((string) file_get_contents(self::WP_SRC . '/' . $rel));

        $this->assertSame(
            $wp,
            $oc,
            "{$rel} diverges from its WP source by more than the documented edits (ABSPATH guard, phpcs pragmas, wp_json_encode→json_encode)"
        );
    }

    public static function kernelFiles(): array
    {
        return [
            ['Core/Engine/Parser.php'],
            ['Core/Engine/Conditionals.php'],
            ['Core/Engine/Plurals.php'],
            ['Core/Engine/PluralArityError.php'],
            ['Core/Engine/PluralFormError.php'],
            ['Core/Engine/Validator.php'],
            ['Core/Render/RenderContext.php'],
        ];
    }

    /**
     * Reduce a kernel source to its logic, erasing exactly the differences the
     * port is allowed to introduce, so any *other* difference fails the assert.
     */
    private static function normalize(string $src): string
    {
        // Apply to both sides: the WP source keeps `wp_json_encode`, the port
        // uses `json_encode` — fold them together.
        $src = str_replace('wp_json_encode', 'json_encode', $src);

        $out = array();
        foreach (preg_split('/\R/', $src) as $line) {
            // Drop the ABSPATH guard and WP phpcs pragma comments.
            if (preg_match('/defined\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\|\|\s*exit;/', $line)) {
                continue;
            }
            if (preg_match('/phpcs:(disable|enable|ignore)/', $line)) {
                continue;
            }
            $out[] = rtrim($line);
        }

        // Collapse consecutive blank lines (the guard removal leaves a blank)
        // and trim leading/trailing blanks so formatting can't cause a diff.
        $joined = implode("\n", $out);
        $joined = preg_replace("/\n{2,}/", "\n\n", $joined);
        return trim($joined);
    }
}
