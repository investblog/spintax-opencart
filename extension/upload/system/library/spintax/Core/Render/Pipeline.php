<?php
/**
 * Spintax render pipeline — the framework-agnostic stage orchestrator.
 *
 * The engine primitives (Parser, Conditionals, Plurals) are each individually pure, but the
 * SEMANTICS live in the order they run in. Conditionals run BOTH before and after variable
 * expansion; plurals run before enumerations (so `{plural %n%: …}` sees a literal count);
 * enumerations inside a `#set` value collapse ONCE at set-time, so `#set %n% = {1|4|9}` binds one
 * stable number rather than re-rolling per reference. Get that order wrong and plurals and
 * conditionals fail silently — which is exactly the bug this engine shipped once, and why the
 * order is a class here rather than a paragraph in a README each host re-implements.
 *
 * Two seams are left for the host, and only two:
 *
 *   - `$source` — fetch the raw text of an `#include "name"`. Fetching is I/O; it belongs to the
 *     host. Everything *around* the fetch — recursion, cycle detection, depth and fan-out budgets,
 *     and scope isolation (a child inherits globals + runtime, never the parent's `#set` locals) —
 *     stays here, so a naive host cannot hang itself.
 *   - `$nested` — an optional pass over the assembled text at stage 9, for host-specific constructs
 *     (the WordPress plugin resolves its own `[spintax …]` shortcodes there). Anything such a
 *     construct needs to survive enumeration and permutation resolution should be listed in
 *     `$protect`, which shields it by placeholder and restores it before stage 9.
 *
 * Deliberately NOT here: caching, template storage, settings, and output sanitisation. A host that
 * emits HTML must run its own sanitiser over the result — this returns pre-sanitise text.
 *
 * @package Spintax\Core\Render
 */

declare(strict_types=1);

namespace Spintax\Core\Render;

use Spintax\Core\Engine\Conditionals;
use Spintax\Core\Engine\Parser;
use Spintax\Core\Engine\Plurals;

final class Pipeline {

	/**
	 * Deepest `#include` chain that will be followed. Beyond it, the directive resolves to nothing.
	 */
	public const MAX_INCLUDE_DEPTH = 10;

	/**
	 * Total `#include` expansions allowed per top-level render.
	 *
	 * The depth limit alone does not bound the work: a template that includes two templates, each
	 * of which includes two more, blows up exponentially without ever repeating a name — the
	 * billion-laughs shape. This budget bounds the total, not just the chain.
	 */
	public const MAX_INCLUDES = 100;

	private Parser $parser;
	private Conditionals $conditionals;
	private Plurals $plurals;

	/**
	 * Host-wide variables, available to every template and every nested child.
	 *
	 * @var array<string, string>
	 */
	private array $globals;

	/**
	 * Raw-source fetcher for `#include "name"`: fn(string $name): ?string. Null means the host does
	 * not support includes, and every directive resolves to an empty string.
	 *
	 * @var callable|null
	 */
	private $source;

	/**
	 * Regexes for host constructs that must survive enumeration/permutation resolution untouched
	 * (e.g. WordPress `[spintax …]` shortcodes, whose square brackets the permutation resolver
	 * would otherwise eat). Shielded by placeholder before stage 6a, restored before stage 9.
	 *
	 * @var string[]
	 */
	private array $protect;

	/**
	 * Optional host pass at stage 9: fn(string $text, RenderContext $child): string.
	 *
	 * @var callable|null
	 */
	private $nested;

	/**
	 * Remaining include expansions for the current top-level render.
	 */
	private int $budget = self::MAX_INCLUDES;

	/**
	 * @param Parser|null           $parser  Parser instance — inject one with a deterministic RNG to make renders reproducible.
	 * @param array<string, string> $globals Host-wide variables.
	 * @param callable|null         $source  fn(string $name): ?string — raw template text for an `#include`, or null when unknown.
	 * @param string[]              $protect Regexes for host constructs to shield from enum/perm resolution.
	 * @param callable|null         $nested  fn(string $text, RenderContext $child): string — host pass at stage 9.
	 */
	public function __construct(
		?Parser $parser = null,
		array $globals = array(),
		?callable $source = null,
		array $protect = array(),
		?callable $nested = null
	) {
		$this->parser       = $parser ?? new Parser();
		$this->conditionals = new Conditionals();
		$this->plurals      = new Plurals();
		$this->globals      = $globals;
		$this->source       = $source;
		$this->protect      = $protect;
		$this->nested       = $nested;
	}

	/**
	 * Render a raw template to pre-sanitise text.
	 *
	 * @param string                $raw          Raw spintax markup.
	 * @param array<string, string> $runtime_vars Runtime variables. They outrank `#set` locals and globals.
	 * @param RenderContext|null    $context      Context to render in. Built from the globals when null.
	 * @param string                $locale       Locale for plural agreement ("ru", "ru_RU", …). Empty selects the two-form default.
	 * @param bool                  $post_process Run the cosmetic tail (spacing, capitalisation, URL/abbreviation shielding).
	 * @return string Pre-sanitise text. A host emitting HTML must sanitise it.
	 */
	public function render(
		string $raw,
		array $runtime_vars = array(),
		?RenderContext $context = null,
		string $locale = '',
		bool $post_process = true
	): string {
		$this->budget = self::MAX_INCLUDES;

		$text = $this->stages(
			$raw,
			$runtime_vars,
			$context ?? new RenderContext( $this->globals ),
			$locale,
			array()
		);

		// Stage 10. Cosmetic post-process, ONCE, over the fully assembled document — including
		// whatever the includes brought in. Running it per-nested-render instead would post-process
		// the same text repeatedly and treat every fragment as if it began a sentence.
		return $post_process ? $this->parser->post_process( $text ) : $text;
	}

	/**
	 * Stages 3-9: everything except the cosmetic tail. Recursion for `#include` re-enters here, so a
	 * nested template is spun in its own scope but is NOT post-processed on its own.
	 *
	 * @param string                $raw          Raw markup.
	 * @param array<string, string> $runtime_vars Runtime variables.
	 * @param RenderContext         $context      Context for this level.
	 * @param string                $locale       Plural locale.
	 * @param array<string, true>   $stack        Include names currently being rendered — the cycle guard.
	 * @return string
	 */
	private function stages(
		string $raw,
		array $runtime_vars,
		RenderContext $context,
		string $locale,
		array $stack
	): string {
		// Stage 3: strip comments.
		$text = $this->parser->strip_comments( $raw );

		// Stage 4: extract #set directives and remove them from the body.
		$extracted = $this->parser->extract_set_directives( $text );
		$text      = $extracted['body'];

		// Stage 4b: collapse enumerations inside #set values ONCE, so a local variable holds a
		// single stable value (`#set %n% = {1|4|9}` becomes "4" and stays "4" at every reference).
		// Critically, it also lets `{plural %n%: …}` see a number: the plural pass runs before
		// enumeration resolution and would otherwise be handed an unresolved `{1|4|9}` and drop the
		// block. Values carrying conditionals or plurals are left alone — they may reference
		// variables defined on other lines and must stay deferred to stages 6a-6d.
		foreach ( $extracted['variables'] as $set_name => $set_value ) {
			if ( false === strpos( $set_value, '{' ) ) {
				continue;
			}
			if ( false !== strpos( $set_value, '{?' ) || false !== strpos( $set_value, '{plural ' ) ) {
				continue;
			}
			$extracted['variables'][ $set_name ] = $this->parser->resolve_enumerations( $set_value );
		}

		// Stage 5: build the variable context. Precedence: runtime > local (#set) > global.
		$context = $context->with_local( $extracted['variables'] );
		if ( ! empty( $runtime_vars ) ) {
			$context = $context->with_runtime( $runtime_vars );
		}
		$all_vars = $context->get_merged_variables();

		// Shield host constructs so the enumeration/permutation resolvers never see their brackets.
		$shielded = array();
		$counter  = 0;
		foreach ( $this->protect as $pattern ) {
			$text = (string) preg_replace_callback(
				$pattern,
				static function ( array $m ) use ( &$shielded, &$counter ): string {
					$key              = "\x00HOST_{$counter}\x00";
					$shielded[ $key ] = $m[0];
					++$counter;
					return $key;
				},
				$text
			);
		}

		// Stage 6a: conditionals, before variable expansion — so only the surviving branch is fed
		// into the expander.
		$text = $this->conditionals->apply( $text, $all_vars );

		// Stage 6b: expand %variables%.
		$text = $this->parser->expand_variables( $text, $all_vars );

		// Stage 6c: conditionals again, after expansion — a substituted value may itself carry one.
		$text = $this->conditionals->apply( $text, $all_vars );

		// Stage 6d: plural agreement. After expansion, so the count slot holds a literal integer.
		// Lenient: one malformed construct renders verbatim instead of crashing the whole render.
		$text = $this->plurals->apply( $text, $locale, array( 'lenient' => true ) );

		// Stage 7: enumerations.
		$text = $this->parser->resolve_enumerations( $text );

		// Stage 8: permutations.
		$text = $this->parser->resolve_permutations( $text );

		// Restore the host constructs — stage 9 is where they get their turn.
		if ( ! empty( $shielded ) ) {
			$text = str_replace( array_keys( $shielded ), array_values( $shielded ), $text );
		}

		// Stage 9: nested templates. The child inherits globals and runtime variables but NOT this
		// template's #set locals — a nested template defines its own.
		$child = $context->for_child_render();

		$text = $this->parser->resolve_includes(
			$text,
			function ( string $name ) use ( $child, $locale, $stack ): string {
				return $this->include_one( $name, $child, $locale, $stack );
			}
		);

		if ( null !== $this->nested ) {
			$text = (string) ( $this->nested )( $text, $child );
		}

		return $text;
	}

	/**
	 * Resolve one `#include "name"`, guarded.
	 *
	 * A cycle, an exhausted depth or fan-out budget, an unknown name, or a host with no source at
	 * all all resolve to an empty string: an include that cannot be honoured contributes nothing,
	 * rather than leaking a directive into the output or hanging the render.
	 *
	 * @param string              $name   Include name as written in the directive.
	 * @param RenderContext       $child  Child context (globals + runtime, no parent locals).
	 * @param string              $locale Plural locale, inherited.
	 * @param array<string, true> $stack  Names currently being rendered.
	 * @return string
	 */
	private function include_one( string $name, RenderContext $child, string $locale, array $stack ): string {
		if ( null === $this->source ) {
			return '';
		}
		if ( isset( $stack[ $name ] ) ) {
			return ''; // Circular reference.
		}
		if ( count( $stack ) >= self::MAX_INCLUDE_DEPTH ) {
			return '';
		}
		if ( $this->budget <= 0 ) {
			return '';
		}

		$raw = ( $this->source )( $name );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}

		--$this->budget;

		return $this->stages( $raw, array(), $child, $locale, $stack + array( $name => true ) );
	}
}
