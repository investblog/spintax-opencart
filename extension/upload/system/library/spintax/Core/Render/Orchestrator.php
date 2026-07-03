<?php
/**
 * Spintax render orchestrator — the OpenCart replacement for the WP `Renderer`.
 *
 * Reproduces the WP `Renderer::process_template()` stage order **verbatim**
 * (spec §9.1), with two deliberate differences:
 *   - The four WordPress seams (template source, cache, settings, sanitize) are
 *     NOT here — they live behind shims wired by the public entry point.
 *   - The terminal `wp_kses_post()` stage is intentionally omitted: this method
 *     returns **pre-sanitize** output. The HtmlSanitizer shim (§9.4) is applied
 *     as a separate terminal stage by `spintax_render()`. The Phase-0
 *     byte-identity gate compares this pre-sanitize output (spec §15).
 *
 * The pure kernel classes (Parser, Conditionals, Plurals, RenderContext) are the
 * ones ported verbatim; this orchestrator is thin glue re-expressing the WP
 * pipeline stage-for-stage so the WP `RendererTest::process_template()` cases
 * pass unchanged.
 *
 * @package Spintax\Core\Render
 */

declare(strict_types=1);

namespace Spintax\Core\Render;

use Spintax\Core\Engine\Conditionals;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Plurals;

final class Orchestrator
{
    private Parser $parser;
    private Conditionals $conditionals;
    private Plurals $plurals;

    /** @var array<string, string> */
    private array $global_vars;

    /**
     * @param Parser|null           $parser      Parser (inject a deterministic RNG for testing).
     * @param array<string, string> $global_vars Site-wide variables (from the SettingsProvider shim).
     */
    public function __construct(?Parser $parser = null, array $global_vars = array())
    {
        $this->parser = $parser ?? new Parser();
        $this->conditionals = new Conditionals();
        $this->plurals = new Plurals();
        $this->global_vars = $global_vars;
    }

    /**
     * Process raw template content through the pipeline stages, returning
     * pre-sanitize text. Mirrors WP `Renderer::process_template()` exactly,
     * minus the terminal `wp_kses_post()`.
     *
     * @param string                $raw              Raw spintax markup.
     * @param array<string, string> $runtime_vars     Runtime variables.
     * @param RenderContext|null     $context          Render context (created from global vars if null).
     * @param string                $locale           Plural locale (raw, e.g. "ru" / "ru_RU"). Empty => EN-style default.
     * @param callable|null         $include_resolver Optional fn(string $slug): string for `#include` (nesting is optional, spec §9.3).
     * @param bool                  $post_process     Run the prose post_process tail (spacing + capitalisation).
     *                                                Pass false for `seo_keyword` slug mode, which must bypass it (§9.5).
     * @return string Pre-sanitize processed text.
     */
    public function process_template(
        string $raw,
        array $runtime_vars = array(),
        ?RenderContext $context = null,
        string $locale = '',
        ?callable $include_resolver = null,
        bool $post_process = true
    ): string {
        $context = $context ?? new RenderContext($this->global_vars);

        // Stage 3: Strip comments.
        $text = $this->parser->strip_comments($raw);

        // Stage 4: Parse #set, strip from body.
        $extracted = $this->parser->extract_set_directives($text);
        $text = $extracted['body'];

        // Stage 5: Build variable context.
        $context = $context->with_local($extracted['variables']);
        if (!empty($runtime_vars)) {
            $context = $context->with_runtime($runtime_vars);
        }
        $all_vars = $context->get_merged_variables();

        // Shield #include lines before spintax resolution so the permutation
        // resolver never sees their brackets (parity with the WP shield stage;
        // OpenCart drops the WP-only [spintax] shortcode).
        $nested_placeholders = array();
        $nested_counter = 0;
        $text = preg_replace_callback(
            '/^[ \t]*#include\s+"[^"]+"\s*$/mu',
            static function (array $m) use (&$nested_placeholders, &$nested_counter): string {
                $key = "\x00NESTED_{$nested_counter}\x00";
                $nested_placeholders[$key] = $m[0];
                ++$nested_counter;
                return $key;
            },
            $text
        );

        // Stage 6a: Conditionals (pre-expand pass).
        $text = $this->conditionals->apply($text, $all_vars);

        // Stage 6b: Expand variables.
        $text = $this->parser->expand_variables($text, $all_vars);

        // Stage 6c: Conditionals (post-expand pass).
        $text = $this->conditionals->apply($text, $all_vars);

        // Stage 6d: Plurals (lenient — a single broken construct renders
        // verbatim with fullwidth braces instead of crashing the render).
        $text = $this->plurals->apply($text, $locale, array('lenient' => true));

        // Stage 7: Resolve enumerations.
        $text = $this->parser->resolve_enumerations($text);

        // Stage 8: Resolve permutations.
        $text = $this->parser->resolve_permutations($text);

        // Restore #include placeholders.
        if (!empty($nested_placeholders)) {
            $text = str_replace(
                array_keys($nested_placeholders),
                array_values($nested_placeholders),
                $text
            );
        }

        // Stage 9: Resolve #include (optional — nesting per spec §9.3).
        if (null !== $include_resolver) {
            $text = $this->parser->resolve_includes($text, $include_resolver);
        }

        // Stage 10: Post-process (spacing, capitalisation). Bypassed in slug
        // mode — §9.5 requires seo_keyword output to skip the prose tail.
        if ($post_process) {
            $text = $this->parser->post_process($text);
        }

        // Stage 11 (sanitize) is deliberately NOT here — see the class docblock.
        return $text;
    }
}
