<?php
/**
 * The named outcome codes returned by `Planner::plan()` (spec §8.4).
 *
 * The applier never returns a bare boolean — every outcome states *why* it did
 * or did not write, so skips are first-class, enumerable and loggable.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class PlanCode
{
    // Writes.
    public const WROTE_SEEDED = 'WROTE_SEEDED';
    public const WROTE_REGENERATED = 'WROTE_REGENERATED';
    public const WROTE_EMPTY = 'WROTE_EMPTY';

    // Skips.
    public const SKIP_MANUAL_EDIT_DETECTED = 'SKIP_MANUAL_EDIT_DETECTED';
    public const SKIP_TARGET_NONEMPTY = 'SKIP_TARGET_NONEMPTY';
    public const SKIP_EMPTY_RENDER = 'SKIP_EMPTY_RENDER';
    public const SKIP_NO_WRITE_TRIGGER = 'SKIP_NO_WRITE_TRIGGER';
    public const SKIP_SOURCE_NOT_FOUND = 'SKIP_SOURCE_NOT_FOUND';
    public const SKIP_COLD_START_MANUAL = 'SKIP_COLD_START_MANUAL';
    public const SKIP_OUT_OF_SCOPE_TYPE = 'SKIP_OUT_OF_SCOPE_TYPE';
    public const SKIP_OUT_OF_SCOPE_STATUS = 'SKIP_OUT_OF_SCOPE_STATUS';
    public const SKIP_LANGUAGE_NOT_INSTALLED = 'SKIP_LANGUAGE_NOT_INSTALLED';
    public const SKIP_STORE_OUT_OF_SCOPE = 'SKIP_STORE_OUT_OF_SCOPE';
    public const SKIP_SEO_KEYWORD_COLLISION = 'SKIP_SEO_KEYWORD_COLLISION';
    public const SKIP_CLEAR_FORBIDDEN_REQUIRED = 'SKIP_CLEAR_FORBIDDEN_REQUIRED';
    public const SKIP_ATTRIBUTE_DELETED = 'SKIP_ATTRIBUTE_DELETED';

    /** @return string[] Every valid code (writes + skips). */
    public static function all(): array
    {
        return array(
            self::WROTE_SEEDED, self::WROTE_REGENERATED, self::WROTE_EMPTY,
            self::SKIP_MANUAL_EDIT_DETECTED, self::SKIP_TARGET_NONEMPTY, self::SKIP_EMPTY_RENDER,
            self::SKIP_NO_WRITE_TRIGGER, self::SKIP_SOURCE_NOT_FOUND, self::SKIP_COLD_START_MANUAL,
            self::SKIP_OUT_OF_SCOPE_TYPE, self::SKIP_OUT_OF_SCOPE_STATUS, self::SKIP_LANGUAGE_NOT_INSTALLED,
            self::SKIP_STORE_OUT_OF_SCOPE, self::SKIP_SEO_KEYWORD_COLLISION,
            self::SKIP_CLEAR_FORBIDDEN_REQUIRED, self::SKIP_ATTRIBUTE_DELETED,
        );
    }

    /** @return bool True if the code represents an actual write. */
    public static function isWrite(string $code): bool
    {
        return in_array($code, array(self::WROTE_SEEDED, self::WROTE_REGENERATED, self::WROTE_EMPTY), true);
    }

    /** Guard/error skips — surfaced as "Blocked" in the Bulk dry-run breakdown. */
    private const BLOCKED = array(
        self::SKIP_CLEAR_FORBIDDEN_REQUIRED,
        self::SKIP_SEO_KEYWORD_COLLISION,
        self::SKIP_ATTRIBUTE_DELETED,
        self::SKIP_SOURCE_NOT_FOUND,
    );

    /**
     * Bucket a code for the dry-run summary: 'write' | 'blocked' | 'skip'.
     * "Blocked" = a guard/error the operator likely wants to act on; plain
     * "skip" = a benign non-write (already filled, manual edit, out of scope…).
     */
    public static function category(string $code): string
    {
        if (self::isWrite($code)) {
            return 'write';
        }
        return in_array($code, self::BLOCKED, true) ? 'blocked' : 'skip';
    }
}
