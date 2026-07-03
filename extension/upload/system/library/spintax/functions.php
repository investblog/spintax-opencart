<?php
/**
 * Global convenience helpers mirroring the WP plugin's `spintax_render()` and
 * the spec §15 signature. Thin wrappers over the `Spintax\Engine` facade with
 * default dependencies; for dependency injection (deterministic RNG, custom
 * sanitizer, global vars) construct `Spintax\Engine` directly.
 *
 * @package Spintax
 */

declare(strict_types=1);

use Spintax\Engine;

if (!function_exists('spintax_render')) {
    /**
     * Render a spintax source to sanitized HTML — the safe default for
     * `description`-style HTML body targets.
     *
     * @param array<string, string> $vars
     */
    function spintax_render(string $source, array $vars = array(), string $locale = ''): string
    {
        return (new Engine())->render($source, $vars, $locale);
    }
}

if (!function_exists('spintax_render_raw')) {
    /**
     * Pre-sanitize orchestrator output — the Phase-0 byte-identity surface
     * (spec §15). Do NOT write this straight into a stored field; sanitize first.
     *
     * @param array<string, string> $vars
     */
    function spintax_render_raw(string $source, array $vars = array(), string $locale = ''): string
    {
        return (new Engine())->renderPreSanitize($source, $vars, $locale);
    }
}

if (!function_exists('spintax_render_plain')) {
    /**
     * Render to plain text (tags stripped) — for `meta_*` targets.
     *
     * @param array<string, string> $vars
     */
    function spintax_render_plain(string $source, array $vars = array(), string $locale = ''): string
    {
        return (new Engine())->renderPlain($source, $vars, $locale);
    }
}

if (!function_exists('spintax_render_slug')) {
    /**
     * Render to a URL-safe, transliterated slug — for `seo_keyword` targets (§9.5).
     *
     * @param array<string, string> $vars
     */
    function spintax_render_slug(string $source, array $vars = array(), string $locale = '', int $max_length = 255): string
    {
        return (new Engine())->renderSlug($source, $vars, $locale, $max_length);
    }
}
