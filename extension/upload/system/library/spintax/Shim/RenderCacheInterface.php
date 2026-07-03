<?php
/**
 * Rendered-output cache seam (spec §9.2). Replaces WP `wp_cache_*`; the OpenCart
 * implementation wraps `system/library/cache` (file/redis/memcached per config).
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

interface RenderCacheInterface
{
    /**
     * @param string $key Cache key.
     * @return string|null Cached value, or null on miss.
     */
    public function get(string $key): ?string;

    /**
     * @param string $key   Cache key.
     * @param string $value Value to store.
     * @param int    $ttl   Seconds to live; a ttl of 0 means "do not cache".
     */
    public function set(string $key, string $value, int $ttl): void;
}
