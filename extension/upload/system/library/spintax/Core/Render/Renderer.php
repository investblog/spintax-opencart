<?php
/**
 * OpenCart render facade — wires the pure orchestrator to the four shims
 * (template source, cache, settings, sanitizer). This is the OC analogue of the
 * WP `Renderer::render()` cache+sanitize wrapper, minus WordPress posts/options.
 *
 * Pipeline: resolve template -> cache check -> orchestrate (pre-sanitize)
 *           -> HtmlSanitizer (terminal) -> cache store -> return.
 *
 * @package Spintax\Core\Render
 */

declare(strict_types=1);

namespace Spintax\Core\Render;

use Spintax\Core\Engine\Parser;
use Spintax\Shim\ArrayRenderCache;
use Spintax\Shim\ArraySettingsProvider;
use Spintax\Shim\HtmlSanitizer;
use Spintax\Shim\HtmlSanitizerInterface;
use Spintax\Shim\RenderCacheInterface;
use Spintax\Shim\SettingsProviderInterface;
use Spintax\Shim\TemplateSourceProviderInterface;

final class Renderer
{
    private Orchestrator $orchestrator;
    private TemplateSourceProviderInterface $templates;
    private RenderCacheInterface $cache;
    private SettingsProviderInterface $settings;
    private HtmlSanitizerInterface $sanitizer;

    public function __construct(
        TemplateSourceProviderInterface $templates,
        ?Parser $parser = null,
        ?RenderCacheInterface $cache = null,
        ?SettingsProviderInterface $settings = null,
        ?HtmlSanitizerInterface $sanitizer = null
    ) {
        $this->templates = $templates;
        $this->settings = $settings ?? new ArraySettingsProvider();
        $this->orchestrator = new Orchestrator($parser ?? new Parser(), $this->settings->global_vars());
        $this->cache = $cache ?? new ArrayRenderCache();
        $this->sanitizer = $sanitizer ?? new HtmlSanitizer();
    }

    /**
     * Render a template by id or slug to sanitized HTML.
     *
     * @param int|string            $id_or_slug   Template id or slug.
     * @param array<string, string> $runtime_vars Runtime variables.
     * @return string Sanitized HTML, or '' when the template is missing/empty.
     */
    public function render($id_or_slug, array $runtime_vars = array()): string
    {
        $tpl = $this->templates->fetch($id_or_slug);
        if (null === $tpl || '' === trim($tpl['source'])) {
            return '';
        }

        $context = new RenderContext($this->settings->global_vars());
        $context_hash = $context->with_runtime($runtime_vars)->get_context_hash();
        $key = 'spintax_render.' . $tpl['id'] . '.' . $context_hash;

        $cached = $this->cache->get($key);
        if (null !== $cached) {
            return $cached;
        }

        $pre = $this->orchestrator->process_template($tpl['source'], $runtime_vars, $context, $tpl['locale']);
        $out = $this->sanitizer->filter($pre);

        $this->cache->set($key, $out, $tpl['ttl']);

        return $out;
    }
}
