# Spintax SEO for OpenCart

Mass-generate **unique** SEO content for your OpenCart catalog — product meta
titles, meta descriptions, meta keywords and descriptions — by rendering
[spintax](https://spintax.net/docs/syntax) templates into stored fields. Preview
every change before it is written, apply in bulk safely, and keep manual edits.

Free and open source (GPL-3.0). A faithful OpenCart port of the WordPress
[**Spintax**](https://wordpress.org/plugins/spintax) plugin.

![License](https://img.shields.io/badge/license-GPL--3.0-blue)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
![OpenCart](https://img.shields.io/badge/OpenCart-3.0.x-1c9dd9)

---

## Why

Writing unique meta for hundreds or thousands of products by hand is impossible;
duplicated boilerplate hurts SEO. Spintax templates let you describe *many*
natural variants once and render a distinct one per product/language:

```
{Buy|Order|Shop for} %name% at a {great|competitive|fair} price.
{Fast|Quick} delivery, {easy|hassle-free} returns.
```

renders, per product, to e.g. *“Order Apple Cinema 30 at a competitive price.
Fast delivery, easy returns.”* — a different combination each time.

## Features

- **Spintax engine** — synonyms `{a|b|c}`, permutations `[<config>a|b|c]`,
  variables `%var%` / `#set`, conditionals `{?VAR?then|else}`, and locale-aware
  plurals `{plural N: one|few|many}` (RU/UK 3-form, EN-style default).
  Full syntax reference: **<https://spintax.net/docs/syntax>**.
- **Entities & targets** — **products, categories, information pages, and
  manufacturers**. Fill description-columns (meta title/description/keyword +
  the HTML description), **SEO-URL keywords** (`oc_seo_url`, with a per-store
  collision guard + optional `-<id>` disambiguation), and **product custom
  attributes** (`oc_product_attribute`, with a resolve-and-verify guard so a
  deleted attribute is skipped, never orphaned).
- **Bindings** — map an entity + target + template, per behavior: seed empty
  only, regenerate on save, preserve manual edits, clear-on-empty (with a hard
  guard so required columns like `meta_title` are never emptied). Optional
  **per-product source override**, and `#include` for reusable template snippets.
- **Multistore** — SEO-URL generation fans out across every store an entity is
  assigned to, restrictable per binding.
- **Automatic updates** — run on the entity's save event, and/or on a
  self-scheduled, token-protected **cron** endpoint (opt-in per binding) that
  re-applies when a template changes and re-seeds missing SEO URLs.
- **Test panel** — preview a single entity/language and see the exact decision
  (write / skip + reason code) plus a **word-level diff** before anything is
  written. Same engine as Apply.
- **Bulk Apply** — **Dry run → Apply**: the dry run previews counts and never
  writes; Apply is chunked, shows a walk lock, and **stops on the first write
  error** (never “best effort”). A server-side snapshot token guarantees Apply
  acts on the exact config you previewed.
- **Activity log** — every apply (save / bulk / cron) is recorded in-admin.
- **All active languages**, HTML sanitisation for description bodies, and
  Cyrillic→latin transliteration for SEO-URL keywords.
- **Zero-config first run** — a ready demo binding is installed; nothing is
  written until you explicitly Dry run → Apply.

## Requirements

- OpenCart **3.0.x** (built and verified on 3.0.5.0)
- PHP **8.1+**
- MySQL / MariaDB

## Install

1. **Extensions → Installer** → upload **`spintax_seo.ocmod.zip`**
   (from [Releases](../../releases), or build it yourself — see *Development*).
2. **Extensions → Modifications** → **Refresh** (adds the sidebar menu entry).
3. **Extensions → Extensions → Modules** → **Spintax SEO** → **Install**
   (creates tables, registers the save events, grants your admin group access).
4. Open **Spintax SEO** from the sidebar (under Extensions).

Uninstall is non-destructive by default (your bindings/templates are kept). To
remove fully: uninstall the module, then remove the modification in **Extensions
→ Modifications**.

## First run — nothing is written until you say so

A demo binding is seeded **enabled** but with *Run on product save* **off**, so it
never writes on its own.

1. On the Spintax SEO page, go to **Bulk Apply**, pick the demo binding.
2. **Dry run** — previews how many cells would be written (no writes).
3. **Apply** — fills exactly those cells.

Use the **Test** panel to preview one product/language first. Turn on *Run on
product save* per binding for automatic updates on every save.

> **Coverage note:** the save event fires for every product write that goes
> through OpenCart’s product model (the admin form and well-behaved
> extensions/imports). A raw-SQL bulk importer bypasses the model — run **Bulk
> Apply** after such an import. (OpenCart 3.0.x core ships no product CSV import
> or product-write API, so this only concerns third-party tools.)

## How it works (architecture)

The engine kernel is framework-agnostic PHP under
`upload/system/library/spintax/`; the OpenCart admin layer is a thin controller
over it. Writes use targeted direct SQL (never the destructive `editProduct`
full-rewrite) plus a cache flush. See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Development

A Docker stack (OpenCart 3.0.5.0 + PHP 8.1 + MariaDB) and a PHPUnit harness are
included.

```bash
docker compose up -d                 # start the stack (see README notes for cli_install)
scripts/dev-provision.sh             # enable SEO URLs + a 2nd language for testing
scripts/test.ps1                     # run the engine test suite (Bash: scripts/test.sh)
scripts/deploy.sh                    # copy extension/upload -> the docroot
docker compose exec -T web php /tmp/build-ocmod.php /opt/spintax /opt/spintax/spintax_seo.ocmod.zip
```

The engine kernel is unit-tested (with byte-identity checks against the original
WordPress kernel); the binding/apply/walk layers are covered by DB integration
tests that self-skip when no database is reachable. CI runs on PHP 8.1 and 8.3.

## Status

Pre-release, feature-complete for the core plan: all four entities
(product / category / information / manufacturer), all three target kinds
(description columns / SEO-URL keywords / product attributes), per-entity sources,
`#include`, multistore SEO-URL fan-out, save-event + self-scheduled cron + Bulk
Apply, an activity log, and the opt-in storefront credit. See
[`CHANGELOG.md`](CHANGELOG.md) for the full list. It is published as a pre-release
while it soaks against more real stores.

## Credits

Ported from the WordPress **Spintax** plugin by [301.st](https://301.st):

- WordPress plugin: <https://wordpress.org/plugins/spintax> · <https://spintax.net>
- Source: <https://github.com/investblog/spintax>

## License

[GPL-3.0-or-later](LICENSE). © 301st.
