<?php
/**
 * Resolved inputs to the pure `Planner::plan()` decision (spec §8.3).
 *
 * Everything the decision needs is pre-resolved by the caller (apply()): scope
 * checks, source resolution, the rendered value and the current target value,
 * plus the binding's behavior flags. Keeping plan() pure means the Test/dry-run
 * panel and the live trigger call the exact same function and can never disagree.
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class PlanInput
{
    /**
     * @param bool        $entityTypeMatches   Entity type is in the binding's scope.
     * @param bool        $entityEnabled       Entity's status is enabled.
     * @param bool        $scopeEnabledOnly    Binding restricts to enabled entities.
     * @param bool        $languageInstalled   Target language is installed/active.
     * @param bool        $storeInScope        Target store is in the entity + binding store scope.
     * @param bool        $attributeOk         EAV attribute resolves (always true for fixed columns).
     * @param bool        $sourceFound         Source resolved (template/per-entity exists).
     * @param string      $rendered            Rendered value for this (language[,store]) cell.
     * @param string      $currentTarget       Current stored value of the target cell.
     * @param string|null $storedSignature     sha1 of the last render, or null on cold start.
     * @param bool        $regenerateOnSave    Overwrite on every trigger.
     * @param bool        $autoSeedEmpty       Write only when the target is empty.
     * @param bool        $preserveManualEdits Skip when a human edited the target.
     * @param bool        $clearOnEmpty        Clear the target on an empty render.
     * @param bool        $isRequiredColumn    Target is a required/display/URL column (never cleared).
     * @param bool        $isSeoKeyword        Target kind is seo_keyword.
     * @param bool        $seoCollides         Rendered keyword collides in oc_seo_url for (store,language).
     * @param bool        $seoDisambiguate     Append -<entity_id> on collision instead of skipping.
     */
    public function __construct(
        public bool $entityTypeMatches = true,
        public bool $entityEnabled = true,
        public bool $scopeEnabledOnly = true,
        public bool $languageInstalled = true,
        public bool $storeInScope = true,
        public bool $attributeOk = true,
        public bool $sourceFound = true,
        public string $rendered = '',
        public string $currentTarget = '',
        public ?string $storedSignature = null,
        public bool $regenerateOnSave = false,
        public bool $autoSeedEmpty = true,
        public bool $preserveManualEdits = true,
        public bool $clearOnEmpty = false,
        public bool $isRequiredColumn = false,
        public bool $isSeoKeyword = false,
        public bool $seoCollides = false,
        public bool $seoDisambiguate = false
    ) {
    }
}
