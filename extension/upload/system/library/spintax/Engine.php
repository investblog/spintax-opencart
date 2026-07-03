<?php
/**
 * Public engine facade — the single entry point OpenCart controllers/models call
 * to render a spintax source into a target field. Wires the pure orchestrator to
 * the output adapters by target kind (spec §8 target classes, §9.4/§9.5 adapters):
 *
 *   - renderPreSanitize() : raw orchestrator output (the Phase-0 byte-identity entry).
 *   - render()            : + HtmlSanitizer — for `description` (HTML body) targets.
 *   - renderPlain()       : tags stripped — for `meta_*` plain-text targets.
 *   - renderSlug()        : + SlugAdapter  — for `seo_keyword` targets.
 *
 * @package Spintax
 */

declare(strict_types=1);

namespace Spintax;

use Spintax\Core\Engine\Parser;
use Spintax\Core\Render\Orchestrator;
use Spintax\Shim\HtmlSanitizer;
use Spintax\Shim\HtmlSanitizerInterface;
use Spintax\Slug\SlugAdapter;

final class Engine
{
    private Orchestrator $orchestrator;
    private HtmlSanitizerInterface $sanitizer;
    private SlugAdapter $slug;

    /**
     * @param Parser|null                $parser      Inject a deterministic RNG for testing.
     * @param array<string, string>      $global_vars Site-wide variables.
     * @param HtmlSanitizerInterface|null $sanitizer  Override the output sanitizer.
     * @param SlugAdapter|null           $slug        Override the slug adapter.
     */
    public function __construct(
        ?Parser $parser = null,
        array $global_vars = array(),
        ?HtmlSanitizerInterface $sanitizer = null,
        ?SlugAdapter $slug = null
    ) {
        $this->orchestrator = new Orchestrator($parser ?? new Parser(), $global_vars);
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer();
        $this->slug = $slug ?? new SlugAdapter();
    }

    /**
     * Raw orchestrator output — pre-sanitize. This is the Phase-0 byte-identity
     * surface (spec §15): identical to the WP kernel before the sanitizer stage.
     *
     * @param array<string, string> $vars
     */
    public function renderPreSanitize(string $source, array $vars = array(), string $locale = ''): string
    {
        return $this->orchestrator->process_template($source, $vars, null, $locale);
    }

    /**
     * Like renderPreSanitize() but bypassing the prose post_process tail — the
     * slug output mode (§9.5). Kept private; callers use renderSlug().
     *
     * @param array<string, string> $vars
     */
    private function renderForSlug(string $source, array $vars, string $locale): string
    {
        return $this->orchestrator->process_template($source, $vars, null, $locale, null, false);
    }

    /**
     * HTML body target (`description`): rendered + allow-list sanitized (§9.4).
     *
     * @param array<string, string> $vars
     */
    public function render(string $source, array $vars = array(), string $locale = ''): string
    {
        return $this->sanitizer->filter($this->renderPreSanitize($source, $vars, $locale));
    }

    /**
     * Plain-text target (`meta_title` / `meta_description` / `meta_keyword`):
     * rendered, then ALL tags stripped and whitespace collapsed. Meta fields
     * carry no markup.
     *
     * @param array<string, string> $vars
     */
    public function renderPlain(string $source, array $vars = array(), string $locale = ''): string
    {
        $text = strip_tags($this->renderPreSanitize($source, $vars, $locale));
        // Collapse the whitespace that stripped tags may leave behind.
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/\s*\n\s*/u', "\n", (string) $text);
        return trim((string) $text);
    }

    /**
     * SEO-URL keyword target (`seo_keyword`): rendered, then slugified +
     * transliterated (§9.5). Bypasses HtmlSanitizer (already URL-safe ASCII).
     *
     * @param array<string, string> $vars
     */
    public function renderSlug(string $source, array $vars = array(), string $locale = '', int $max_length = 255): string
    {
        // Bypass post_process (§9.5) — the slug adapter does its own lowercase /
        // transliterate / hyphenate; the prose capitalisation+respacing tail must
        // not run for URL keywords.
        return $this->slug->to_slug($this->renderForSlug($source, $vars, $locale), $max_length);
    }
}
