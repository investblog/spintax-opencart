<?php
/**
 * In-memory TemplateSourceProvider for tests / standalone rendering.
 *
 * The OpenCart-DB-backed provider (over oc_spintax_template / oc_spintax_source)
 * lands in Phase 1; the engine depends only on the interface.
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

final class ArrayTemplateSourceProvider implements TemplateSourceProviderInterface
{
    /** @var array<string, array{id: int|string, source: string, locale: string, ttl: int}> */
    private array $by_key = array();

    /**
     * Register a template.
     *
     * @param int|string $id     Template id.
     * @param string     $source Raw spintax source.
     * @param string     $slug   Optional slug alias.
     * @param string     $locale Plural locale (e.g. "ru-RU").
     * @param int        $ttl    Cache TTL in seconds.
     */
    public function add($id, string $source, string $slug = '', string $locale = '', int $ttl = 3600): self
    {
        $record = array('id' => $id, 'source' => $source, 'locale' => $locale, 'ttl' => $ttl);
        $this->by_key[(string) $id] = $record;
        if ('' !== $slug) {
            $this->by_key[$slug] = $record;
        }
        return $this;
    }

    public function fetch($id_or_slug): ?array
    {
        return $this->by_key[(string) $id_or_slug] ?? null;
    }
}
