<?php
/**
 * Dry-run snapshot token (spec §7.1). A deterministic fingerprint of the exact
 * config + scope a Bulk "Dry run" measured, so "Apply" can prove it is acting on
 * the same reality and reject a stale/mismatched snapshot (STALE_SNAPSHOT).
 *
 * It freezes CONFIG + SCOPE (what/where), NOT per-cell catalog content — each
 * Apply chunk re-runs plan() live, so a manual edit made after the dry run is
 * still preserved (see §7.1 and the UI contract in docs/ui-phase1.md).
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class DryRunToken
{
    /**
     * Compute the token from already-resolved fields.
     *
     * @param string   $bindingId
     * @param string   $bindingModified   binding.date_modified (string form)
     * @param int      $templateId
     * @param string   $templateModified  template.date_modified (string form; '' if none)
     * @param int      $cacheVersion      binding.cache_version
     * @param int[]    $activeLanguageIds active language ids in scope
     * @param string   $storeScope        e.g. 'ALL' or a CSV of store ids
     */
    public static function compute(
        string $bindingId,
        string $bindingModified,
        int $templateId,
        string $templateModified,
        int $cacheVersion,
        array $activeLanguageIds,
        string $storeScope
    ): string {
        $langs = array_map('intval', $activeLanguageIds);
        sort($langs, SORT_NUMERIC);

        $parts = array(
            $bindingId,
            $bindingModified,
            (string) $templateId,
            $templateModified,
            (string) $cacheVersion,
            implode(',', $langs),
            $storeScope,
        );

        return sha1(implode('|', $parts));
    }
}
