<?php
/**
 * In-memory SettingsProvider for tests / standalone rendering. The OpenCart
 * `spintax_seo`-setting-backed impl lands in Phase 1.
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

final class ArraySettingsProvider implements SettingsProviderInterface
{
    /** @var array<string, string> */
    private array $global_vars;
    private int $default_ttl;

    /**
     * @param array<string, string> $global_vars
     */
    public function __construct(array $global_vars = array(), int $default_ttl = 3600)
    {
        $this->global_vars = $global_vars;
        $this->default_ttl = $default_ttl;
    }

    public function global_vars(): array
    {
        return $this->global_vars;
    }

    public function default_ttl(): int
    {
        return $this->default_ttl;
    }
}
