<?php
/**
 * Settings / global-variables seam (spec §9.2). Replaces WP `get_option`; the
 * OpenCart implementation reads the `spintax_seo` setting group.
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

interface SettingsProviderInterface
{
    /**
     * @return array<string, string> Site-wide template variables (name => value).
     */
    public function global_vars(): array;

    /**
     * @return int Default rendered-output cache TTL, in seconds.
     */
    public function default_ttl(): int;
}
