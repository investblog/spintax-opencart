<?php
/**
 * Binding-id helpers (spec §4.4, §4.6). A binding id is `bind_` + 6 lowercase
 * hex chars (= 11 chars total, matching the `char(11)` PK).
 *
 * Ported from the WP plugin's `Validators`, dropping the `wp_generate_password`
 * fallback: PHP 8.1 always has `random_bytes`.
 *
 * @package Spintax\Support
 */

declare(strict_types=1);

namespace Spintax\Support;

final class BindingId
{
    private const PATTERN = '/^bind_[a-z0-9]{6}$/';

    public static function isValid($id): bool
    {
        return is_string($id) && 1 === preg_match(self::PATTERN, $id);
    }

    /**
     * Generate a fresh binding id: `bind_` + 6 hex chars (24 bits — ample below
     * the 200-binding cap).
     */
    public static function generate(): string
    {
        return 'bind_' . bin2hex(random_bytes(3));
    }
}
