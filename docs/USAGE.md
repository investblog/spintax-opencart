# Spintax SEO for OpenCart — User Guide

Mass-generate **unique** SEO content for your whole catalog from **spintax** templates —
meta title/description/keyword, HTML descriptions, SEO-URL keywords and product attributes,
for products, categories, information pages and manufacturers, across every language and store.

- **Free & open-source** (GPL-3.0) — no "pro" tier, no forced storefront links.
- Source & issues: <https://github.com/investblog/spintax-opencart>
- Spintax syntax reference: <https://spintax.net/docs/syntax>

> **One thing the syntax site does not cover yet: `#def`.** `#set %x% = {a|b}` is a *macro* — it is
> re-picked at every use, so the same variable can read differently twice on one page. `#def %x% =
> {a|b}` is picked **once per render** and held. Reach for `#def` whenever two mentions disagreeing
> would look like a mistake — a product name, a tone, and above all a number you both print and
> agree grammatically (`#def %n% = {1|4}` then `{plural %n%: item|items}`). Under `#set` that plural
> block renders empty, because the count is still spintax when the plural is decided.

---

## 1. Requirements

- OpenCart **3.0.x** (built and verified on 3.0.5.0)
- PHP **8.1+**
- MySQL / MariaDB

No Composer, no external services, and nothing to configure at the server level — the package
is self-contained.

## 2. Installation

1. **Admin → Extensions → Installer** → upload `spintax_seo.ocmod.zip`.
2. **Extensions → Modifications → Refresh** (clears the OCMOD cache).
3. **Extensions → Extensions → Modules** → find **Spintax SEO** → click **Install** (the green +).
   Installation creates the extension's tables, registers its events, and grants your user group
   permission.
4. Open **Extensions → Modules → Spintax SEO → Edit** to reach the dashboard.

> The admin UI ships in **English, Ukrainian and Russian** — it follows your admin language automatically.

To update, upload the new zip, Refresh modifications, and the dashboard reflects the new version.

## 3. Quick start (5 minutes)

A ready-to-use **demo binding** is installed and enabled — but it **writes nothing on its own**.

1. Open the dashboard. You'll see a demo binding (Product → `meta_description`) and a demo template.
2. Scroll to **Bulk Apply**, pick the demo binding, and click **Dry run**. This previews how many
   product meta descriptions *would* be filled — **nothing is written yet**.
3. Click **Apply**. The extension fills the empty meta descriptions from the template, in chunks,
   across all your languages.
4. Check a product's SEO tab — its meta description is now populated with a unique variant.

That's the whole model: **templates + bindings → Dry run → Apply.**

## 4. Core concepts

### Templates
A **template** is a spintax source string, e.g.:

```
{Buy|Order|Shop} {%name%|%name% online} at a {great|fair} price.
```

Each render produces a different, unique variant. Templates are reusable and can pull in other
templates with `#include` (see §9). Manage them on the **Templates** tab.

### Bindings
A **binding** connects an **entity + target + template** and decides *how* it writes. Fields:

- **Entity** — Product, Category, Information page, or Manufacturer.
- **Target kind**:
  - **Description column** — a meta/description field (see §5).
  - **SEO-URL keyword** — the friendly-URL slug (`oc_seo_url`).
  - **Product attribute** — a custom attribute's text (products only).
- **Source mode** — *Template* (one shared source) or *Per entity* (each product can override the
  source on its own "Spintax SEO" tab; the template is the fallback).
- **Behavior** (§6), **Trigger** (§7), **Run on cron** (§7), **Stores** (§8), **Active**.

## 5. Targets

| Target kind | Fills | Entities | Notes |
|-------------|-------|----------|-------|
| Description column | `meta_title`, `meta_description`, `meta_keyword`, HTML `description` | product / category / information | `meta_*` are plain text; `description` is HTML-sanitised |
| SEO-URL keyword | the entity's friendly URL slug | product / category / information / manufacturer | per-store, cross-language collision guard; optional `-<id>` disambiguation |
| Product attribute | `oc_product_attribute` text | product only | skips safely if the attribute is later deleted |

**Guard rails:** required/display columns (like `meta_title` when it can't be empty) are never
cleared; a SEO URL is never removed by an empty render.

## 6. Behavior flags

- **Seed once (fill empty only)** — write only where the target is currently empty. Safe default.
- **Regenerate on save** — re-render every time (see Trigger).
- **Preserve manual edits** — if you edited a value by hand after Spintax wrote it, Spintax won't
  overwrite it. (Detected by a stored signature — a manual change breaks the signature and is kept.)
- **Clear target on empty render** — when the template renders empty, blank the target (respecting
  the required-field guard).

## 7. The workflow & automation

### Test panel (single entity, dry run)
Pick a binding + entity + language and see the exact decision — **write / skip + reason code** —
plus a **word-level diff** of the current vs rendered value, before anything is written.

### Bulk Apply (whole catalog)
**Dry run → Apply.** The dry run previews counts (write / skip / blocked) and writes nothing. Apply
runs in **chunks**, shows a progress bar and a **lock** (so two applies can't collide), and **stops
on the first write error** (never "best effort"). A server-side **snapshot token** guarantees Apply
acts on the exact configuration you previewed — if you change the template meanwhile, you're asked
to re-run the Dry run.

### Save-event trigger
Set a binding's **Trigger** to on to also seed/regenerate whenever the entity is saved in the admin.
Off (default) = the binding only runs from Bulk Apply.

### Cron (auto-refresh)
OpenCart 3 has no built-in scheduler, so the extension ships its own **tokenised storefront endpoint**.
The **Settings** panel shows a ready-to-use URL:

```
0 * * * *  wget -qO-  "https://your-store.com/index.php?route=extension/module/spintax_seo/cron&token=YOUR_TOKEN"
```

- It's **opt-in per binding**: set **Run on cron = Auto** on the bindings you want it to manage
  (Off by default — the demo binding is never auto-run).
- It self-schedules (default hourly), re-applies bindings when a template changes, and re-seeds
  missing SEO URLs. Calling it more often than the interval is a cheap no-op.
- Keep the token secret; a wrong/absent token returns 403.

## 8. Multi-store & multi-language

- Every binding renders **per language** automatically (all your active languages).
- **SEO-URL keywords** fan out across every store the entity is assigned to; restrict with the
  **Stores** field (`ALL` or a comma-separated list of store ids).
- Description/attribute targets are store-agnostic (one value per language).

## 9. `#include` — reusable partials

A template can pull in another by name:

```
Main copy here.
#include "shared-footer"
```

Includes are resolved recursively (with cycle, depth and fan-out guards). Editing or renaming an
included template correctly marks the dependent bindings **stale** (run Bulk Apply to propagate),
and a template that's included by another can't be deleted until it's freed.

## 10. Per-entity source override

With a product binding in **Per entity** source mode, each product gets a **Spintax SEO** tab where
you can set a bespoke source for that product; the binding's template is the fallback when it's blank.

## 11. Activity log

The **Activity log** panel records every apply — save / bulk / cron — with written / skipped /
blocked counts, so you can see what Spintax did and when. It's self-pruning.

## 12. Settings — optional storefront credit

Off by default. When enabled, a single crawlable "SEO by Spintax" link is added to the storefront
footer. The extension works identically with it off — **it is never required or forced**.

## 13. Uninstalling

- **Uninstall** (Extensions → Modules) removes the events and permission but **keeps your data and
  the generated catalog content** (non-destructive).
- To also drop the extension's tables, use the "delete all data" path. Your catalog fields that were
  filled remain in the catalog either way — Spintax writes standard OpenCart fields, not a shadow store.

## 14. FAQ / troubleshooting

- **"Config changed since the Dry run"** — you edited the binding/template after the Dry run. Re-run
  the Dry run so Apply acts on the current config.
- **"Another Apply is already running"** — a walk lock is held. If it's stuck, use **Force release lock**.
- **A binding shows Stale** — a template it uses (or includes) changed. Run Bulk Apply to propagate.
- **Nothing is written on Apply** — check the Dry run breakdown: values may be *skipped* (already
  filled + Seed-once, or a preserved manual edit) or *blocked* (SEO-URL collision, missing source).
- **UI is in English on a non-English admin** — install that admin language pack in OpenCart; the
  extension ships en-gb / uk-ua / ru-ru and follows the admin language.

## 15. Links

- Repository, issues, releases: <https://github.com/investblog/spintax-opencart>
- Spintax syntax & playground: <https://spintax.net>
- Author: [301.st](https://301.st) · ported from the Spintax WordPress plugin.
- License: [GPL-3.0](../LICENSE).
