# WP kernel test corpus — reference for the Phase 0 byte-identity gate

These six files are copied **verbatim** from the WordPress Spintax plugin
(`tests/Core/{Engine,Render}/` in <https://github.com/investblog/spintax>). They
are the authoritative behavioural spec for the pure kernel classes ported here.

They are kept **outside** `../tests/` so PHPUnit does not try to run them before
the kernel exists. During Phase 0, port each into `../tests/Kernel/` and change:

- the namespace of the test to `Spintax\Tests\Kernel\...`;
- the base class — **all six files currently `extends \WP_UnitTestCase`** (a WP
  test-suite base). Swap it for `PHPUnit\Framework\TestCase` and drop any WP-only
  `setUp`/factory/object-cache helpers. The pure kernel needs none of them; RNG is
  injected by closure (see `ParserTest::make_sequence`), not by WP fixtures;
- **nothing in the assertions or expected values** — those are the byte-identity contract.

Split by concern:
- **5 pure-kernel files** (`Parser`, `Conditionals`, `Plurals`, `RenderContext`,
  `Validator`) → `../tests/Kernel/` in step 0.3.
- **`RendererTest`** → step 0.5 (`process_template()` cases → orchestrator tests) and
  step 0.6 (cache-reuse / ttl cases → `RenderCache` shim tests — they exercise the
  caching seam, which in WP is the object cache and in OC is the `RenderCache` shim,
  **not** the pure kernel).

The **byte-identity gate** (spec §15 Phase 0) is: the ported kernel must pass this
corpus unchanged (API-level identity), *and* re-rendering the golden inputs with a
fixed RNG must reproduce `../tests/fixtures/{rendered-output,review-casino}.txt`
**before** the HtmlSanitizer stage (§9.4 — the sanitizer diverges from `wp_kses_post`
by design, so the comparison is scoped to pre-sanitize output).

RNG is injected via the `Parser` constructor: `new Parser(fn(int $min,int $max):int)`.
Deterministic helpers in `ParserTest` (`make_first` → returns `$min`, `make_last`
→ `$max`, `make_sequence` → scripted sequence) are the template for deterministic
tests — reuse them.

Provenance: copied 2026-07-02 from the live WP plugin (wordpress.org/plugins/spintax).
Do not edit here; edit the ported copies under `../tests/Kernel/`.
