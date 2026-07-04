<?php
/**
 * Builds the `#include "name"` resolver the render pipeline calls (spec §9.3,
 * optional nesting). The pure kernel (`Parser::resolve_includes`) does a single
 * pass: `#include "x"` → `$resolver('x')`. Recursion, cycle detection and a depth
 * cap live HERE (the WP kernel keeps the same split).
 *
 * The resolver looks a template up BY NAME, renders it through stages 3–9 with a
 * nested resolver (so nested `#include`s and spintax inside the included template
 * resolve too), and returns that pre-post-process / pre-sanitize text for the
 * parent to splice in and finish. Guards:
 *   - **cycle**: a visited-set (normalized name) → a template that (transitively)
 *     includes itself yields '' at the point of recursion, not an infinite loop;
 *   - **depth**: a hard cap (default 5) bounds runaway nesting;
 *   - **fan-out budget**: `$visited` stops cycles but NOT a template that includes
 *     the same partial many times wide+deep (a "billion laughs" — b^depth renders).
 *     A shared, by-reference total-expansion budget caps the number of resolutions
 *     regardless of shape, so a pathological set can't hang a Bulk Apply;
 *   - **memoization**: name→source is cached per build() so repeated includes of
 *     the same partial issue ONE DB lookup, not one per occurrence;
 *   - **missing**: an unknown name renders as '' (graceful, never fatal).
 *
 * @package Spintax\Core\Template
 */

declare(strict_types=1);

namespace Spintax\Core\Template;

use Spintax\Engine;

final class IncludeResolver
{
    public const MAX_DEPTH = 5;

    /** Hard cap on total include resolutions per top-level render (anti billion-laughs). */
    public const MAX_TOTAL = 200;

    /**
     * @param Engine   $engine
     * @param callable $lookupByName fn(string $name): ?string — template source by name, null if missing.
     * @param array<string, string> $vars   render variables (shared with the parent render).
     * @param string   $locale
     * @param int      $maxDepth
     * @param int      $maxTotal   total-expansion budget (all branches combined).
     * @return callable fn(string $name): string
     */
    public static function build(Engine $engine, callable $lookupByName, array $vars, string $locale, int $maxDepth = self::MAX_DEPTH, int $maxTotal = self::MAX_TOTAL): callable
    {
        // $visited is threaded BY VALUE down each include chain (correct cycle
        // scoping: siblings stay independent). $budget and $cache are shared BY
        // REFERENCE across all branches: $budget bounds total expansion (fan-out),
        // $cache memoizes the name→source lookup (kills duplicate DB queries).
        $budget = $maxTotal;
        /** @var array<string, ?string> $cache */
        $cache = array();

        $render = static function (string $name, array $visited, int $depth) use (&$render, &$budget, &$cache, $engine, $lookupByName, $vars, $locale, $maxDepth): string {
            $key = mb_strtolower(trim($name));
            if ('' === $key || $depth > $maxDepth || isset($visited[$key]) || $budget <= 0) {
                return '';
            }
            --$budget; // count this resolution against the shared fan-out budget

            if (!array_key_exists($key, $cache)) {
                $cache[$key] = $lookupByName($name); // ?string; memoized (incl. misses)
            }
            $source = $cache[$key];
            if (null === $source) {
                return '';
            }

            $visited[$key] = true;
            $nested = static function (string $inner) use (&$render, $visited, $depth): string {
                return $render($inner, $visited, $depth + 1);
            };
            return $engine->renderForInclude((string) $source, $vars, $locale, $nested);
        };

        return static function (string $name) use (&$render): string {
            return $render($name, array(), 1);
        };
    }
}
