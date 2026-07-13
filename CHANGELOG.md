# Changelog

All notable changes to **Spintax SEO** are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/); the project ships date-based
pre-releases while it stabilises toward a 1.0.

## [0.2.6] — 2026-07-14

The engine stops being a copy. It is now the pinned Composer package
[`spintax/core`](https://packagist.org/packages/spintax/core) — the same engine the WordPress plugin
runs, MIT, zero dependencies. No behaviour change: the rendered output of 0.2.6 is byte-identical to
0.2.5, and the committed 47 KB golden did not move.

### Changed
- **`spintax/core` is a dependency, not a vendored kernel.** OpenCart has no Composer at run time —
  the OCMOD ships `upload/` and nothing else — so `composer run sync-kernel` unpacks the pinned
  package into `upload/system/library/spintax/Core/`, where the extension's own PSR-4 autoloader
  finds it. To move the engine: `composer update spintax/core && composer run sync-kernel`, then
  commit. A test boots the shipped tree in a Composer-free PHP process, exactly the way OpenCart
  loads it in production.
- **`PortIntegrityTest` is gone, and its lesson is worth keeping.** It existed to prove the copied
  kernel had not diverged from the WordPress engine — and it stayed green while the kernel drifted
  **two commits behind**, missing both the `mailto:`/`tel:` shield and the Spanish sentence openers.
  It compared two copies that both lived *inside this repository*, so they were stale together and it
  agreed with itself. A checksum against a second local copy proves consistency, never freshness.
  `KernelLoadsTest` now compares the runtime tree against the **pinned package**, which is external
  and versioned — so a stale kernel is a red test instead of a silent divergence.

### Removed
- `extension/reference/wp-kernel-src/` and `wp-kernel-tests/` — the vendored WordPress kernel and its
  test corpus. The pin replaces them.
- The ported engine tests (`Parser`, `Conditionals`, `Plurals`, `Validator`, `RenderContext`). They
  test the package's code, and the package tests it — against the shared cross-engine golden corpus,
  in its own CI. The suite here keeps what is actually OpenCart's: orchestrator, bindings, apply and
  walk, install, DB, sanitiser, slugs. 234 tests, 701 assertions, green.

## [0.2.5] — 2026-07-13

Metadata/documentation release. No engine, schema or behaviour change — the rendered output
of 0.2.5 is byte-identical to 0.2.4.

### Fixed
- **The OCMOD version no longer lies.** `install.xml` had carried `<version>1.0.0</version>`
  since the first release, so OpenCart's **Extensions → Modifications** list showed `1.0.0`
  for every build regardless of which one you actually installed — there was no way to tell
  from the admin which version was running. It now tracks the release number (`0.2.5`) and
  will be bumped with every release from here on.

### Changed
- `docs/ARCHITECTURE.md` brought in line with the code as built: six tables (the `spintax_log`
  activity log was missing) and all 14 registered events across the four entities (it still
  described a product-only event set).

## [0.2.4] — 2026-07-13

Post-process fixes, synced from the WordPress kernel (spintax 2.3.3). Engine-only — no OpenCart
glue, schema or admin change.

### Fixed
- **A run of sentence punctuation is no longer split apart.** `Wait... what?` rendered as
  `Wait. . . What?`, `Wow!!!` as `Wow! ! !` and `Really?!` as `Really? !` — the "add a space after
  `.!?`" rule fired *between* the marks of a run. A run is now one sentence end, in every language.
- **`mailto:` and `tel:` links survive rendering.** `<a href="mailto:support@example.com">` came out
  as `href="mailto: support@example.com"` — a broken link: the address was shielded out from under
  its prefix, and the leftover colon then got a space. The committed orchestrator golden had this
  defect frozen into it, which is precisely what a regression lock is supposed to expose — its only
  change in this release is that one space disappearing from an 18 KB render.
- **Spanish sentence openers (`¿` `¡`).** The capitaliser upper-cases the first *character* after a
  sentence end; an inverted mark has no uppercase form, so every Spanish question quietly kept a
  lowercase first letter. Openers now carry the capital through — including `¡¿Qué haces?!` (two
  marks) and `<p>¿<a href="/ayuda">Necesitas ayuda</a>?</p>` (an opener followed by markup).

### Changed
- Kernel re-synced from the WordPress plugin: `Core/Engine/Parser.php` and the ported ParserTest
  corpus (+13 post-process cases). `PortIntegrityTest` byte-identity with `reference/wp-kernel-src`
  holds; the suite is 490 tests. The same three fixes ship in the WP plugin (2.3.3) and
  `@spintax/core`, and are locked by the shared cross-engine golden corpus.

## [0.2.3] — 2026-07-10

### Changed
- **SQL queries now build every value with an inline `$this->db->escape()` / `(int)`
  cast at the point of use**, instead of interpolating a pre-escaped variable into the
  query string (`"… WHERE name = '{$n}'"`). Behaviour is identical — the values were
  already escaped — but OpenCart marketplace and forum moderation scanners flag
  interpolated queries regardless of prior escaping, so the extension now passes those
  automated checks cleanly.

### Added
- **CI SQL-safety lint** (`scripts/lint-sql.php`, wired into `.github/workflows/lint.yml`)
  — fails the build if any shipped query is assembled with string interpolation, so the
  pattern above can never regress into a release.

## [0.2.2] — 2026-07-04

### Added
- **Ukrainian (`uk-ua`) and Russian (`ru-ru`) admin translations** — the admin UI now
  follows your OpenCart admin language (English / Ukrainian / Russian).
- **User guide** ([`docs/USAGE.md`](docs/USAGE.md)) — installation, quick start, concepts,
  the Dry run → Apply workflow, cron setup, multi-store / multi-language, `#include`, and
  troubleshooting.

## [0.2.1] — 2026-07-04

### Fixed
- **`#include` dependencies now invalidate bindings.** Editing or renaming a template
  that another template `#include`s now bumps the cache_version of the dependent
  bindings (so the Stale badge lights, a stale dry-run can't apply, and cron
  re-applies), and deleting a still-included template is refused. Include names are
  resolved to templates the same way rendering does, so the two never disagree.
- **`#include` works in SEO-URL keyword templates**, not only description/attribute
  templates — a slug template can now pull in a shared partial.
- **Bulk Apply and the activity log count “blocked” cells** (SEO-URL collision,
  missing source, forbidden clear) instead of folding them into “skipped”.

## [0.2.0] — 2026-07-04

### Added
- **`eav_attribute` target** — fill product custom attributes (`oc_product_attribute`)
  from a template, with a runtime resolve-and-verify guard: a deleted attribute is
  safely skipped, never orphaned. Completes the target matrix (description column /
  SEO-URL keyword / product attribute).
- **`seo_keyword` target** — generate SEO-URL slugs into `oc_seo_url` with a
  per-store, cross-language collision guard and opt-in `-<id>` disambiguation; a URL
  is never cleared by an empty render.
- **Manufacturer entity** — SEO-URL keyword generation for manufacturers (which have
  no description/meta table).
- **Multistore** — `seo_keyword` fans out across every store an entity is assigned
  to, restrictable per binding via a store scope.
- **Self-scheduled cron** — a tokenized storefront endpoint (OpenCart 3 core has no
  `cron/cron`) that re-applies opted-in bindings when templates change and re-seeds
  missing SEO URLs. Opt-in per binding, self-throttling, honours the walk lock and
  resumes long walks across ticks.
- **Per-entity source mode** — each product can override the shared template from its
  own “Spintax SEO” tab (falls back to the template when blank).
- **`#include`** — templates can include other templates by name, with cycle, depth,
  and fan-out (billion-laughs) guards.
- **Activity log** — an admin Logs panel recording every apply across the three
  triggers (save / bulk / cron), self-bounded by pruning.
- **Test-panel diff** — a word-level current → rendered diff, so an operator sees
  exactly what an Apply would change first.
- **Opt-in storefront credit** — a single optional, crawlable footer link to
  spintax.net (default **off**; never required, never forced).

### Fixed
- Engine: `#set %n% = {1|4|9}` now collapses the enumeration at set-time, so a
  following `{plural %n%: …}` sees a numeric count instead of unresolved spintax and
  no longer drops the block (ported from the upstream WordPress kernel).

## [0.1.0] — 2026-07-03

Initial public pre-release. Ported the Spintax content engine to OpenCart 3.x:
- Product meta/description seeding from spintax templates across all store languages.
- The `plan()` / `apply()` decision tree with seed-once, regenerate-on-save, and
  manual-edit preservation (signature-based).
- Bulk Apply with a chunked, lockable walk and a dry-run snapshot token.
- Admin MVC-L: bindings, templates, single-entity Test (dry run), Bulk Apply.
- Byte-identical engine kernel (parser / conditionals / plurals) with a shared
  fixture corpus against the WordPress origin.

[0.2.2]: https://github.com/investblog/spintax-opencart/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/investblog/spintax-opencart/compare/v0.2.0...v0.2.1
[0.2.0]: https://github.com/investblog/spintax-opencart/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/investblog/spintax-opencart/releases/tag/v0.1.0
