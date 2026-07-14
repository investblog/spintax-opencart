# Architecture

A short map of how Spintax SEO for OpenCart is put together. Full spintax syntax
is documented at <https://spintax.net/docs/syntax>.

## Layers

```
upload/system/library/spintax/          # framework-agnostic engine (no OpenCart deps)
  Core/Engine/    Parser, Conditionals, Plurals, Validator      # the spintax kernel
  Core/Render/    Orchestrator, RenderContext, Renderer         # stage pipeline
  Core/Binding/   Planner, Applier, Walk, BindingAdmin, …       # decide + apply
  Core/Template/  TemplateRepository
  Shim/           HtmlSanitizer, RenderCache, SettingsProvider, TemplateSourceProvider
  Slug/           SlugAdapter                                   # SEO-URL keyword mode
  Db/             DbInterface, MysqliDb, OcDb                   # thin DB seam
  Engine.php, functions.php, autoload.php                       # facade + entry points

upload/admin/…extension/module/spintax_seo.*                    # thin OpenCart MVC-L
install.xml                                                      # OCMOD (sidebar menu)
```

The **kernel** (`Core/Engine`, `Core/Render`) is not a copy of anything: it is the
[**`spintax/core`**](https://packagist.org/packages/spintax/core) Composer package
(MIT, zero dependencies) — the very same engine the WordPress *Spintax* plugin
runs — pinned in `composer.json` and pulled in as a dependency.

OpenCart has no Composer at run time (the OCMOD ships `upload/` and nothing else,
loaded by the extension's own PSR-4 autoloader), so the engine cannot live in
`vendor/`: `composer run sync-kernel` unpacks the pinned package into
`upload/system/library/spintax/Core/`, which is the tree that gets zipped.
`KernelLoadsTest` fails if that tree drifts from the pin, and a second test boots
the shipped tree in a Composer-free PHP process — the way OpenCart actually loads
it — so a broken sync is caught the way it would really break.

OpenCart-specific seams (template source, cache, settings, output sanitisation) are
supplied as small **shims** around the package.

## Rendering pipeline

`Orchestrator::process_template()` runs the stages in a fixed order: strip
comments → extract `#set` → build variable context → conditionals (pre) → expand
`%vars%` → conditionals (post) → plurals → enumerations → permutations → nested
`#include` → post-process. It returns **pre-sanitize** text; the terminal
`HtmlSanitizer` (for HTML body targets) or the `SlugAdapter` (for SEO-URL
keywords) is applied by the public `Engine` facade depending on the target kind.

## Bindings, plan() and apply()

A **binding** maps an entity + target field + template source, plus behavior
flags (seed-once, regenerate-on-save, preserve-manual-edits, clear-on-empty). The
decision is a **pure function** `Planner::plan(PlanInput)` returning one named
outcome code (e.g. `WROTE_SEEDED`, `SKIP_TARGET_NONEMPTY`,
`SKIP_MANUAL_EDIT_DETECTED`, `SKIP_CLEAR_FORBIDDEN_REQUIRED`, …). The **Test
panel**, **Bulk dry-run** and **Apply** all call the same `plan()` on the same
resolved inputs, so a preview can never disagree with a live write.

Manual edits are detected by comparing `sha1(current value)` against a stored
**signature**; a required/display column (`meta_title`) is never cleared.

## The walk (Bulk Apply)

`Walk::dryRun()` counts write/skip/blocked over the whole scope without writing
and mints a **snapshot token** (a fingerprint of the binding + template +
active-language/store scope). `Walk::applyChunk()` requires that token — a change
to the config or scope between Dry run and Apply is rejected (`STALE_SNAPSHOT`).
A per-binding **walk lock** (compare-and-set on a timestamp) serialises apply
sessions; a live lock lets only the owner continue. Any **write error halts the
walk** (not best-effort) and withholds the completion stamp.

## OpenCart integration

The admin controller is thin: it wires OpenCart's `$this->db` (via `OcDb`) and the
render `Engine` into the tested library and exposes AJAX endpoints. `install()`
creates the tables, registers the **events**, grants permissions and seeds a demo
binding; `uninstall()` deregisters and (optionally) drops data. All catalog writes
go through **targeted direct SQL** plus a `cache->delete(...)` for the entity —
never the destructive model rewrite.

**14 events are registered** (`Installer::allEvents()` is the exact set): each of the
four entities — product, category, information, manufacturer — contributes an
`add`/`edit`/`delete` model hook, plus two module-level hooks (the opt-in storefront
credit on the catalog footer, and the product-form preload for `per_entity` sources).

## Storage

Six `DB_PREFIX`-prefixed InnoDB tables: `spintax_binding`, `spintax_template`,
`spintax_source`, `spintax_signature`, `spintax_walk` and `spintax_log` (the activity
log, pruned to the last 500 runs). `Install\Schema` holds the DDL. Small scalar
settings live in OpenCart's `oc_setting` under the `spintax_seo` group.

## Testing

The engine is tested where it lives — in the `spintax/core` package, against a corpus
of fixtures shared by every Spintax engine (PHP and TypeScript), so the implementations
cannot drift apart. This repository tests what is actually OpenCart's: the render
orchestrator, bindings, plan/apply, the chunked walk, install, the HTML sanitiser and
slug transliteration. Plus two guards on the packaging itself — `KernelLoadsTest` (the
shipped kernel matches the pin) and a Composer-free boot of the shipped tree.

The binding/apply/walk/template/install layers have DB integration tests that self-skip
when no database is reachable, so `scripts/test.*` is green both on the dev stand and in
CI (PHP 8.1 / 8.3).
