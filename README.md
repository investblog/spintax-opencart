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
- **Bindings** — map an entity + target field + template, per behavior: seed
  empty only, regenerate on save, preserve manual edits, clear-on-empty (with a
  hard guard so required columns like `meta_title` are never emptied).
- **Test panel** — preview a single product/language and see the exact decision
  (write / skip + reason code) before anything is written. Same engine as Apply.
- **Bulk Apply** — **Dry run → Apply**: the dry run previews counts and never
  writes; Apply is chunked, shows a walk lock, and **stops on the first write
  error** (never “best effort”). A server-side snapshot token guarantees Apply
  acts on the exact config you previewed.
- **All active languages**, HTML sanitisation for description bodies, and
  Cyrillic→latin transliteration for future SEO-URL keywords.
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

Phase 1 (MVP) is complete: **Product** entity, description-column targets, all
active languages, default store, template source mode, save-event + Bulk Apply,
zero-config first run, OCMOD package. Planned next: Category / Information /
Manufacturer entities, per-entity sources, SEO-URL keyword targets, and
multi-store.

## Credits

Ported from the WordPress **Spintax** plugin by [301.st](https://301.st):

- WordPress plugin: <https://wordpress.org/plugins/spintax> · <https://spintax.net>
- Source: <https://github.com/investblog/spintax>

## License

[GPL-3.0-or-later](LICENSE). © 301st.
