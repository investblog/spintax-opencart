<?php
/**
 * Spintax render pipeline — the framework-agnostic stage orchestrator.
 *
 * The engine primitives (Parser, Conditionals, Plurals) are each individually pure, but the
 * SEMANTICS live in the order they run in. Conditionals run BOTH before and after variable
 * expansion; plurals run before enumerations (so `{plural %n%: …}` sees a literal count); and a
 * `#def` value is rendered ONCE, after the context exists, so every reference to it sees the same
 * text, while a `#set` value is substituted verbatim and re-rolls at each reference. Get that order
 * wrong and plurals and conditionals fail silently — which is exactly the bug this engine shipped
 * once, and why the order is a class here rather than a paragraph in a README each host
 * re-implements.
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

		// Stage 4: extract #set and #def directives and remove them from the body.
		$extracted = $this->parser->extract_directives( $text );
		$text      = $extracted['body'];

		// Stage 5: build the variable context. Precedence: runtime > local > global — and
		// `get_merged_variables()` enforces that by merge order, not by the order these calls
		// are made in.
		$context = $context->with_local( $extracted['set'] );
		if ( ! empty( $runtime_vars ) ) {
			$context = $context->with_runtime( $runtime_vars );
		}

		// Stage 5b: roll `#def` values ONCE, and only now — the full context has to exist first.
		// A `#def` value is rendered as if it were a miniature body and the result is frozen for
		// every reference, which is what `#set` deliberately does NOT do: a `#set` value is
		// substituted verbatim and its brackets resolve independently at each reference.
		//
		// Doing this before stage 5 (where the old collapse-once pass sat) would hand the roll a
		// context with no globals and no runtime variables, so `#def %x% = %product_name% {a|b}`
		// would freeze the literal text `%product_name%`.
		if ( ! empty( $extracted['def'] ) ) {
			$context = $context->with_local(
				$this->roll_definitions( $extracted['def'], $context, $runtime_vars, $locale )
			);
		}

		$all_vars = $context->get_merged_variables();

		// Shield host constructs so the enumeration/permutation resolvers never see their brackets.
		$shielded = array();
		$counter  = 0;
		$text     = $this->shield_host_constructs( $text, $shielded, $counter );

		// Stage 6a: conditionals, before variable expansion — so only the surviving branch is fed
		// into the expander.
		$text = $this->conditionals->apply( $text, $all_vars );

		// Stage 6b: expand %variables%.
		$text = $this->parser->expand_variables( $text, $all_vars );

		// Shield again. The pass above is the only place a host construct can enter the document
		// after the first shield — carried in by a `#set`, a global, a runtime variable or a frozen
		// `#def`, none of whose values were part of the body when it ran. Without this, stage 8
		// reads `[host id="1"]` as a single-element permutation and strips the brackets, so the
		// construct reaches the stage 9 hook as inert text. That was true of `#set` and globals
		// long before `#def` existed; the roll stage simply gave the hole a second entrance.
		$text = $this->shield_host_constructs( $text, $shielded, $counter );

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
	 * Render each `#def` value once and return the frozen results.
	 *
	 * Values are rendered in dependency order so a `#def` built out of another `#def` sees the
	 * resolved text rather than the raw template. `Parser::order_definitions()` works that order
	 * out, following `#set` aliases as well as direct references — the dependency in
	 * `#def %b% = %s%` / `#set %s% = %a%` / `#def %a% = …` is real but invisible in `%b%`'s text.
	 *
	 * A name that a runtime variable also defines is skipped: runtime outranks locals, so rolling
	 * it would be work whose result nothing can read.
	 *
	 * @param array<string, string> $definitions  Raw `#def` values, name => value.
	 * @param RenderContext         $context      Context with globals, `#set` locals and runtime.
	 * @param array<string, string> $runtime_vars Runtime variables, which outrank every local.
	 * @param string                $locale       Plural locale.
	 * @return array<string, string> Frozen values, name => rendered text.
	 */
	private function roll_definitions(
		array $definitions,
		RenderContext $context,
		array $runtime_vars,
		string $locale
	): array {
		$vars      = $context->get_merged_variables();
		$outranked = array_change_key_case( $runtime_vars, CASE_LOWER );
		$resolved  = array();

		// The alias map is every macro value a definition can actually see — globals and runtime
		// variables as well as local `#set`. Passing only the local map would miss a dependency
		// routed through a global: `#def %b% = %s%` with a global `%s% = %a%` and a local
		// `#def %a%`.
		//
		// Excluded from it are the definitions that will actually be rolled, because a `#def`
		// shadows a global of the same name and hopping through the shadowed value would compute
		// the wrong graph. A definition a runtime variable outranks is NOT excluded: it is never
		// rolled, so the runtime value is the one that will really be substituted, and the graph
		// has to follow it. Excluding those too made a dependency reached through such a name
		// invisible, and declaration order leaked back into the result.
		$aliases = array_diff_key( $vars, array_diff_key( $definitions, $outranked ) );

		foreach ( $this->parser->order_definitions( $definitions, $aliases ) as $name ) {
			if ( array_key_exists( $name, $outranked ) ) {
				continue;
			}

			$resolved[ $name ] = $this->render_definition_value(
				$definitions[ $name ],
				array_merge( $vars, $resolved ),
				$locale
			);
		}

		return $resolved;
	}

	/**
	 * Render one `#def` value through the same passes the body gets, in the same order.
	 *
	 * Stage 9 (`#include`) is deliberately absent: includes resolve after everything here and
	 * cannot be frozen into a value.
	 *
	 * @param string                $value  Raw directive value.
	 * @param array<string, string> $vars   Variables visible to this value.
	 * @param string                $locale Plural locale.
	 * @return string
	 */
	private function render_definition_value( string $value, array $vars, string $locale ): string {
		// A host construct is opaque wherever it is written, including inside a definition. Shield
		// it for the length of the roll and hand it back whole, so the frozen value carries the
		// construct rather than the wreckage of one.
		$shielded = array();
		$counter  = 0;
		$value    = $this->shield_host_constructs( $value, $shielded, $counter );

		$value = $this->conditionals->apply( $value, $vars );
		$value = $this->parser->expand_variables( $value, $vars );

		// Shield again, for the same reason the body does: expansion is the one place a host
		// construct can enter after the first pass. `#def %frag% = %s%` with
		// `#set %s% = [host id="1"]` pulls it in here, and without this the permutation resolver
		// below reads it as a single-element permutation and strips the brackets.
		$value = $this->shield_host_constructs( $value, $shielded, $counter );

		$value = $this->conditionals->apply( $value, $vars );
		$value = $this->plurals->apply( $value, $locale, array( 'lenient' => true ) );
		$value = $this->parser->resolve_enumerations( $value );
		$value = $this->parser->resolve_permutations( $value );

		if ( ! empty( $shielded ) ) {
			$value = str_replace( array_keys( $shielded ), array_values( $shielded ), $value );
		}

		return $value;
	}

	/**
	 * Replace host constructs with opaque placeholders.
	 *
	 * The placeholders are `\x00HOST_n\x00`, which no template syntax can produce and no resolver
	 * reads. Callers share `$shielded` and `$counter` across successive calls, so one restore at
	 * the end covers every pass.
	 *
	 * @param string                $text     Text to shield.
	 * @param array<string, string> $shielded Placeholder => original, accumulated by reference.
	 * @param int                   $counter  Placeholder counter, advanced by reference.
	 * @return string
	 */
	private function shield_host_constructs( string $text, array &$shielded, int &$counter ): string {
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
