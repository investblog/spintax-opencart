<?php
/**
 * In-memory RenderCache for tests / standalone rendering. A ttl of 0 stores
 * nothing (mirrors "cache disabled" — spec §7.1 ttl=0 path). Ignores expiry
 * timing (no clock dependency); the OpenCart-cache-backed impl lands in Phase 1.
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

final class ArrayRenderCache implements RenderCacheInterface
{
    /** @var array<string, string> */
    private array $store = array();

    public function get(string $key): ?string
    {
        return $this->store[$key] ?? null;
    }

    public function set(string $key, string $value, int $ttl): void
    {
        if ($ttl <= 0) {
            unset($this->store[$key]);
            return;
        }
        $this->store[$key] = $value;
    }
}
