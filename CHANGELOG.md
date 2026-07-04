# Changelog

All notable changes to **Spintax SEO** are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/); the project ships date-based
pre-releases while it stabilises toward a 1.0.

## [Unreleased]

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

[Unreleased]: https://github.com/investblog/spintax-opencart/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/investblog/spintax-opencart/releases/tag/v0.1.0
