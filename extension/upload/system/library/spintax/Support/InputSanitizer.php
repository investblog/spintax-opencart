<?php
/**
 * Input cleaning for raw spintax markup — the OpenCart port of the WP plugin's
 * `Spintax\Support\Validators` input-sanitisation helpers (spec §9.2).
 *
 * Only the framework-agnostic input-cleaning helpers are ported. The WP
 * post-meta / post-column reserved-key guards (`is_reserved_meta_key`,
 * `is_post_column`, …) do NOT apply to OpenCart — the binding layer's own
 * required-column guard (`SKIP_CLEAR_FORBIDDEN_REQUIRED`, spec §8) replaces them.
 *
 * @package Spintax\Support
 */

declare(strict_types=1);

namespace Spintax\Support;

/**
 * Static input-cleaning helpers.
 */
final class InputSanitizer
{
    /**
     * Sanitize raw spintax markup from user input.
     *
     * Cannot use a strip-tags sanitiser: angle-bracket expressions such as
     * `[<minsize=2;sep=", ">a|b]` are valid spintax permutation syntax. This
     * addresses the real concerns — invalid UTF-8, null bytes, control chars —
     * without breaking the markup.
     *
     * Port note: WP's `wp_check_invalid_utf8($raw, true)` becomes
     * `mb_convert_encoding($raw, 'UTF-8', 'UTF-8')` (spec §9.2). We first set
     * the substitute character to none so invalid sequences are *stripped*
     * (matching WP's strip behaviour) rather than replaced with '?'.
     *
     * @param string $raw Raw spintax text from the request.
     * @return string Sanitized text safe for storage.
     */
    public static function sanitize_spintax(string $raw): string
    {
        // Strip invalid UTF-8 sequences (substitute-none => removed, not '?').
        $prev = mb_substitute_character();
        mb_substitute_character('none');
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        mb_substitute_character($prev);

        // Remove null bytes and other control characters except \n and \t.
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);

        // Normalize line endings.
        $raw = str_replace("\r\n", "\n", $raw);
        $raw = str_replace("\r", "\n", $raw);

        return $raw;
    }

    /**
     * Clamp an integer to a min/max range.
     *
     * @param int $value Value to clamp.
     * @param int $min   Minimum allowed value.
     * @param int $max   Maximum allowed value.
     * @return int Clamped value.
     */
    public static function clamp_int(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Normalise a global-variables array (name => value).
     *
     * Lowercases + trims names, strips `%` wrappers, drops empty names.
     * Non-array input yields an empty map (the OC SettingsProvider supplies
     * its own defaults, unlike the WP plugin which folded them in here).
     *
     * @param mixed $raw Raw value (from settings).
     * @return array<string, string>
     */
    public static function normalize_global_variables($raw): array
    {
        if (!is_array($raw)) {
            return array();
        }

        $normalised = array();
        foreach ($raw as $name => $value) {
            $name = strtolower(trim((string) $name));
            if ('' === $name) {
                continue;
            }
            $name = trim($name, '%');
            $normalised[$name] = (string) $value;
        }

        return $normalised;
    }
}
