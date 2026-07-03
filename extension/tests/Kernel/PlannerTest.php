<?php
/**
 * Phase 1.0c gate — the pure plan() decision tree (spec §8.3/§8.4).
 * Every one of the 16 outcome codes is exercised, plus the priority ordering
 * (scope filters precede render) and manual-edit detection.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Binding\PlanCode;
use Spintax\Core\Binding\Planner;
use Spintax\Core\Binding\PlanInput;

final class PlannerTest extends TestCase
{
    private Planner $planner;

    protected function setUp(): void
    {
        $this->planner = new Planner();
    }

    // --- Step 1: scope filters ----------------------------------------------

    public function test_out_of_scope_type(): void
    {
        $this->assertSame(
            PlanCode::SKIP_OUT_OF_SCOPE_TYPE,
            $this->planner->plan(new PlanInput(entityTypeMatches: false))
        );
    }

    public function test_out_of_scope_status(): void
    {
        $this->assertSame(
            PlanCode::SKIP_OUT_OF_SCOPE_STATUS,
            $this->planner->plan(new PlanInput(entityEnabled: false, scopeEnabledOnly: true))
        );
    }

    public function test_disabled_entity_allowed_when_scope_not_enabled_only(): void
    {
        // Not a scope skip → proceeds to a normal seed.
        $this->assertSame(
            PlanCode::WROTE_SEEDED,
            $this->planner->plan(new PlanInput(entityEnabled: false, scopeEnabledOnly: false, rendered: 'x'))
        );
    }

    public function test_language_not_installed(): void
    {
        $this->assertSame(
            PlanCode::SKIP_LANGUAGE_NOT_INSTALLED,
            $this->planner->plan(new PlanInput(languageInstalled: false))
        );
    }

    public function test_store_out_of_scope(): void
    {
        $this->assertSame(
            PlanCode::SKIP_STORE_OUT_OF_SCOPE,
            $this->planner->plan(new PlanInput(storeInScope: false))
        );
    }

    // --- Step 2/3: target validation + source -------------------------------

    public function test_attribute_deleted(): void
    {
        $this->assertSame(
            PlanCode::SKIP_ATTRIBUTE_DELETED,
            $this->planner->plan(new PlanInput(attributeOk: false))
        );
    }

    public function test_source_not_found(): void
    {
        $this->assertSame(
            PlanCode::SKIP_SOURCE_NOT_FOUND,
            $this->planner->plan(new PlanInput(sourceFound: false))
        );
    }

    // --- Path B: auto_seed_empty --------------------------------------------

    public function test_seeded(): void
    {
        $this->assertSame(
            PlanCode::WROTE_SEEDED,
            $this->planner->plan(new PlanInput(rendered: 'Fresh copy', currentTarget: ''))
        );
    }

    public function test_target_nonempty(): void
    {
        $this->assertSame(
            PlanCode::SKIP_TARGET_NONEMPTY,
            $this->planner->plan(new PlanInput(rendered: 'x', currentTarget: 'already here'))
        );
    }

    public function test_empty_render_on_seed(): void
    {
        $this->assertSame(
            PlanCode::SKIP_EMPTY_RENDER,
            $this->planner->plan(new PlanInput(rendered: '   ', currentTarget: ''))
        );
    }

    public function test_seed_seo_collision_skips(): void
    {
        $this->assertSame(
            PlanCode::SKIP_SEO_KEYWORD_COLLISION,
            $this->planner->plan(new PlanInput(rendered: 'slug', isSeoKeyword: true, seoCollides: true))
        );
    }

    public function test_seed_seo_collision_disambiguated_writes(): void
    {
        $this->assertSame(
            PlanCode::WROTE_SEEDED,
            $this->planner->plan(new PlanInput(rendered: 'slug', isSeoKeyword: true, seoCollides: true, seoDisambiguate: true))
        );
    }

    // --- Path A: regenerate_on_save -----------------------------------------

    public function test_regenerated_cold_start_empty_target(): void
    {
        $this->assertSame(
            PlanCode::WROTE_REGENERATED,
            $this->planner->plan(new PlanInput(rendered: 'New', regenerateOnSave: true, currentTarget: ''))
        );
    }

    public function test_regenerate_respects_matching_signature(): void
    {
        // Signature matches current target => not a manual edit => rewrite.
        $current = 'engine wrote this';
        $this->assertSame(
            PlanCode::WROTE_REGENERATED,
            $this->planner->plan(new PlanInput(
                rendered: 'New',
                regenerateOnSave: true,
                currentTarget: $current,
                storedSignature: sha1($current),
            ))
        );
    }

    public function test_manual_edit_detected(): void
    {
        $this->assertSame(
            PlanCode::SKIP_MANUAL_EDIT_DETECTED,
            $this->planner->plan(new PlanInput(
                rendered: 'New',
                regenerateOnSave: true,
                currentTarget: 'human edited this',
                storedSignature: sha1('engine wrote something else'),
            ))
        );
    }

    public function test_cold_start_manual(): void
    {
        $this->assertSame(
            PlanCode::SKIP_COLD_START_MANUAL,
            $this->planner->plan(new PlanInput(
                rendered: 'New',
                regenerateOnSave: true,
                currentTarget: 'pre-existing catalog copy',
                storedSignature: null,
            ))
        );
    }

    public function test_regenerate_empty_clear_forbidden_required(): void
    {
        $this->assertSame(
            PlanCode::SKIP_CLEAR_FORBIDDEN_REQUIRED,
            $this->planner->plan(new PlanInput(
                rendered: '',
                regenerateOnSave: true,
                preserveManualEdits: false,
                clearOnEmpty: true,
                isRequiredColumn: true,
            ))
        );
    }

    public function test_regenerate_empty_clears_non_required(): void
    {
        $this->assertSame(
            PlanCode::WROTE_EMPTY,
            $this->planner->plan(new PlanInput(
                rendered: '',
                regenerateOnSave: true,
                preserveManualEdits: false,
                clearOnEmpty: true,
                isRequiredColumn: false,
            ))
        );
    }

    public function test_regenerate_empty_no_clear_skips(): void
    {
        $this->assertSame(
            PlanCode::SKIP_EMPTY_RENDER,
            $this->planner->plan(new PlanInput(
                rendered: '',
                regenerateOnSave: true,
                preserveManualEdits: false,
                clearOnEmpty: false,
            ))
        );
    }

    public function test_regenerate_seo_collision_skips(): void
    {
        $this->assertSame(
            PlanCode::SKIP_SEO_KEYWORD_COLLISION,
            $this->planner->plan(new PlanInput(
                rendered: 'slug',
                regenerateOnSave: true,
                preserveManualEdits: false,
                isSeoKeyword: true,
                seoCollides: true,
            ))
        );
    }

    public function test_regenerate_seo_collision_disambiguated_writes(): void
    {
        $this->assertSame(
            PlanCode::WROTE_REGENERATED,
            $this->planner->plan(new PlanInput(
                rendered: 'slug',
                regenerateOnSave: true,
                preserveManualEdits: false,
                isSeoKeyword: true,
                seoCollides: true,
                seoDisambiguate: true,
            ))
        );
    }

    // --- Path C --------------------------------------------------------------

    public function test_no_write_trigger(): void
    {
        $this->assertSame(
            PlanCode::SKIP_NO_WRITE_TRIGGER,
            $this->planner->plan(new PlanInput(rendered: 'x', autoSeedEmpty: false, regenerateOnSave: false))
        );
    }

    // --- Ordering + purity ---------------------------------------------------

    public function test_scope_filter_precedes_render_even_with_content(): void
    {
        // Wrong type must short-circuit BEFORE any write path, regardless of flags.
        $this->assertSame(
            PlanCode::SKIP_OUT_OF_SCOPE_TYPE,
            $this->planner->plan(new PlanInput(
                entityTypeMatches: false,
                rendered: 'lots of content',
                regenerateOnSave: true,
            ))
        );
    }

    public function test_manual_edit_precedes_seo_collision(): void
    {
        // A human-edited target skips as manual BEFORE we even consider collision.
        $this->assertSame(
            PlanCode::SKIP_MANUAL_EDIT_DETECTED,
            $this->planner->plan(new PlanInput(
                rendered: 'slug',
                regenerateOnSave: true,
                currentTarget: 'human',
                storedSignature: sha1('engine'),
                isSeoKeyword: true,
                seoCollides: true,
            ))
        );
    }

    public function test_scope_reject_returns_null_when_all_pass(): void
    {
        $this->assertNull($this->planner->scopeReject(new PlanInput(rendered: 'x')));
    }

    public function test_scope_reject_surfaces_each_cheap_filter(): void
    {
        $this->assertSame(PlanCode::SKIP_OUT_OF_SCOPE_TYPE, $this->planner->scopeReject(new PlanInput(entityTypeMatches: false)));
        $this->assertSame(PlanCode::SKIP_OUT_OF_SCOPE_STATUS, $this->planner->scopeReject(new PlanInput(entityEnabled: false)));
        $this->assertSame(PlanCode::SKIP_LANGUAGE_NOT_INSTALLED, $this->planner->scopeReject(new PlanInput(languageInstalled: false)));
        $this->assertSame(PlanCode::SKIP_STORE_OUT_OF_SCOPE, $this->planner->scopeReject(new PlanInput(storeInScope: false)));
        $this->assertSame(PlanCode::SKIP_ATTRIBUTE_DELETED, $this->planner->scopeReject(new PlanInput(attributeOk: false)));
        $this->assertSame(PlanCode::SKIP_SOURCE_NOT_FOUND, $this->planner->scopeReject(new PlanInput(sourceFound: false)));
    }

    public function test_scope_reject_agrees_with_plan(): void
    {
        // Whenever scopeReject rejects, plan() must return the same code (single
        // source of truth — the Test panel and live trigger can't disagree).
        foreach ([
            new PlanInput(entityTypeMatches: false, rendered: 'x'),
            new PlanInput(entityEnabled: false, rendered: 'x'),
            new PlanInput(sourceFound: false, rendered: 'x'),
        ] as $in) {
            $this->assertSame($this->planner->scopeReject($in), $this->planner->plan($in));
        }
    }

    public function test_empty_present_source_is_not_source_not_found(): void
    {
        // sourceFound=true + empty render => render-empty path, NOT SKIP_SOURCE_NOT_FOUND (§8.3).
        $this->assertSame(
            PlanCode::SKIP_EMPTY_RENDER,
            $this->planner->plan(new PlanInput(sourceFound: true, rendered: '', currentTarget: ''))
        );
    }

    public function test_plan_only_ever_returns_known_codes(): void
    {
        $codes = PlanCode::all();
        $this->assertCount(16, $codes);
        // A spread of inputs; every result must be a declared code.
        foreach ([true, false] as $regen) {
            foreach ([true, false] as $seed) {
                foreach (['', 'x'] as $rendered) {
                    foreach (['', 'y'] as $target) {
                        $code = $this->planner->plan(new PlanInput(
                            rendered: $rendered,
                            currentTarget: $target,
                            regenerateOnSave: $regen,
                            autoSeedEmpty: $seed,
                        ));
                        $this->assertContains($code, $codes);
                    }
                }
            }
        }
    }
}
