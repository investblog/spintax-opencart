# Changelog

All notable changes to this project are documented here. This project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased] — Phase 1 (MVP)

### Added
- **Spintax engine** ported from the WordPress *Spintax* plugin: synonyms
  `{a|b|c}`, permutations `[<config>…]`, variables `%var%` / `#set`, conditionals
  `{?VAR?then|else}`, and locale-aware plurals `{plural N: …}` (RU/UK 3-form,
  EN-style default). Verified byte-identical to the WordPress kernel (pre-sanitize).
- **Bindings** — Product entity × description-column targets (`meta_title`,
  `meta_description`, `meta_keyword`, `description`) × all active languages,
  `template` source mode, with seed-once / regenerate / preserve-manual-edits /
  clear-on-empty behavior and a required-column clear guard.
- **Save event** (opt-in per binding) that seeds/regenerates on product add/edit,
  and an orphan-purge on delete.
- **Test panel** — single-cell dry-run showing the exact decision + reason code.
- **Bulk Apply** — Dry run → Apply, gated by a server-side snapshot token,
  chunked, with a visible walk lock and a hard stop on the first write error.
- **Template editor** with syntax validation and a sample preview.
- **HtmlSanitizer** (allow-list) for description bodies and a slug adapter with
  Cyrillic→latin transliteration (for future SEO-URL keywords).
- **Zero-config first run** — a ready demo binding (writes nothing until Apply).
- **OCMOD package** (`spintax_seo.ocmod.zip`) with an admin sidebar menu entry.

### Notes
- Writes use targeted direct SQL (never the destructive full-record rewrite) plus
  a product-cache flush.
- Requires OpenCart 3.0.x and PHP 8.1+.
