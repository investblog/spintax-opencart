<?php
/**
 * The pure `plan()` decision tree (spec §8.3). Given fully-resolved inputs it
 * returns exactly one named PlanCode — no side effects, no I/O. `apply()` (the
 * live trigger) and the Test/dry-run panel both call this, so a preview can
 * never disagree with a live write.
 *
 * Order is part of the contract: cheap scope filters -> target validation ->
 * source resolution -> render/write. Expensive rendering never runs for an
 * entity a cheap filter rejects (the caller resolves inputs in that order).
 *
 * @package Spintax\Core\Binding
 */

declare(strict_types=1);

namespace Spintax\Core\Binding;

final class Planner
{
    /**
     * Steps 1–3 of the decision tree (§8.3): the CHEAP filters that do not need
     * the rendered value or the current target — scope, target validation, and
     * source resolution. Returns the skip code if any rejects, else null.
     *
     * Callers (apply()) run this BEFORE rendering so expensive rendering never
     * runs for an entity a cheap filter rejects. `plan()` calls it too, so the
     * two paths can never disagree.
     */
    public function scopeReject(PlanInput $in): ?string
    {
        // --- Step 1: scope filters -------------------------------------------
        if (!$in->entityTypeMatches) {
            return PlanCode::SKIP_OUT_OF_SCOPE_TYPE;
        }
        if ($in->scopeEnabledOnly && !$in->entityEnabled) {
            return PlanCode::SKIP_OUT_OF_SCOPE_STATUS;
        }
        if (!$in->languageInstalled) {
            return PlanCode::SKIP_LANGUAGE_NOT_INSTALLED;
        }
        if (!$in->storeInScope) {
            return PlanCode::SKIP_STORE_OUT_OF_SCOPE;
        }

        // --- Step 2: runtime target validation (eav only) --------------------
        if (!$in->attributeOk) {
            return PlanCode::SKIP_ATTRIBUTE_DELETED;
        }

        // --- Step 3: resolve source -----------------------------------------
        // NOTE: sourceFound = the source RECORD resolves (template/per-entity
        // exists). An EMPTY-but-present source is sourceFound=true and falls
        // through to render-empty (§8.3) — it is NOT SKIP_SOURCE_NOT_FOUND.
        if (!$in->sourceFound) {
            return PlanCode::SKIP_SOURCE_NOT_FOUND;
        }

        return null;
    }

    public function plan(PlanInput $in): string
    {
        $scope = $this->scopeReject($in);
        if (null !== $scope) {
            return $scope;
        }

        // --- Step 4: render + hash ------------------------------------------
        $rendered_empty = ('' === trim($in->rendered));
        $target_empty = ('' === trim($in->currentTarget));

        if ($in->regenerateOnSave) {
            // Path A — regenerate.
            if ($in->preserveManualEdits) {
                if (null !== $in->storedSignature) {
                    if (sha1($in->currentTarget) !== $in->storedSignature) {
                        return PlanCode::SKIP_MANUAL_EDIT_DETECTED;
                    }
                } elseif (!$target_empty) {
                    // Cold start with pre-existing copy → treat as manual.
                    return PlanCode::SKIP_COLD_START_MANUAL;
                }
            }

            if ($in->isSeoKeyword && $in->seoCollides) {
                return $in->seoDisambiguate ? PlanCode::WROTE_REGENERATED : PlanCode::SKIP_SEO_KEYWORD_COLLISION;
            }

            if ($rendered_empty) {
                if ($in->clearOnEmpty && $in->isRequiredColumn) {
                    return PlanCode::SKIP_CLEAR_FORBIDDEN_REQUIRED;
                }
                if ($in->clearOnEmpty) {
                    return PlanCode::WROTE_EMPTY;
                }
                return PlanCode::SKIP_EMPTY_RENDER;
            }

            return PlanCode::WROTE_REGENERATED;
        }

        if ($in->autoSeedEmpty) {
            // Path B — seed empty.
            if (!$target_empty) {
                return PlanCode::SKIP_TARGET_NONEMPTY;
            }
            if ($in->isSeoKeyword && $in->seoCollides) {
                return $in->seoDisambiguate ? PlanCode::WROTE_SEEDED : PlanCode::SKIP_SEO_KEYWORD_COLLISION;
            }
            if ($rendered_empty) {
                return PlanCode::SKIP_EMPTY_RENDER;
            }
            return PlanCode::WROTE_SEEDED;
        }

        // Path C — neither write flag set.
        return PlanCode::SKIP_NO_WRITE_TRIGGER;
    }
}
