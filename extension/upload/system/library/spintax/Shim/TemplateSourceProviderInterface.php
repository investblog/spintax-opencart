<?php
/**
 * Template resolution + locale seam (spec §9.2).
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

interface TemplateSourceProviderInterface
{
    /**
     * Resolve a template by id or slug.
     *
     * @param int|string $id_or_slug Template id or slug.
     * @return array{id: int|string, source: string, locale: string, ttl: int}|null
     *         Null when no template matches.
     */
    public function fetch($id_or_slug): ?array;
}
