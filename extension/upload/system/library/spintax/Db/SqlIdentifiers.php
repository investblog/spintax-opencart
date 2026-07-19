<?php
/**
 * The two things this extension splices into SQL that are not values.
 *
 * OpenCart 3.x has no prepared statements — its `DB` layer offers `escape()` and nothing else — so
 * every query in this codebase is a concatenated string by necessity. That is fine for *values*:
 * they go through `escape()` or an `(int)` cast. It is the two remaining cases that need a home,
 * because both are identifiers rather than values and neither can be escaped:
 *
 *   - the table prefix, and
 *   - an `IN (…)` list of ids.
 *
 * Both live here so there is **one** place to audit instead of seventy query sites, and so the
 * proof of safety sits next to the string that reaches SQL rather than a few lines above it at the
 * call site. An automated scanner cannot follow that indirection — and neither, reliably, can a
 * reviewer at 2am.
 *
 * @package Spintax\Db
 */

declare(strict_types=1);

namespace Spintax\Db;

trait SqlIdentifiers
{
    /**
     * Backtick-quote a prefixed table name.
     *
     * The prefix is `DB_PREFIX` from OpenCart's `config.php` — a deployment constant the store
     * owner picks at install time. It is not request data and no admin form writes it.
     *
     * `$name` is usually a bare literal, but not always: the entity-driven queries pass
     * `$entity->baseTable` and friends, which come from the hardcoded `EntityRegistry`. That is
     * exactly why the guard exists rather than a convention — it rejects anything but lowercase
     * letters and underscores, so a table name cannot become a hole even when it arrives in a
     * variable.
     */
    private function table(string $name): string
    {
        if (1 !== preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException('Table name must be a bare literal, got: ' . $name);
        }

        return '`' . $this->prefix . $name . '`';
    }

    /**
     * Validate a column name, and return it unchanged.
     *
     * A column is an identifier, so `escape()` cannot protect it — quoting a value and naming a
     * column are different problems. Until now these were spliced raw and were safe only because a
     * whitelist ran a level or two up the call stack (`isValidColumn()`, or the hardcoded
     * `EntityRegistry`). That is the same indirection this trait exists to remove: the proof sat at
     * the caller, not at the string reaching SQL.
     *
     * Unlike `table()` this does NOT add backticks, and the asymmetry is deliberate. A column
     * already sits inside a backticked slot in every format string here (`` `%s` ``), so quoting it
     * again would emit ``` ``name`` ``` and break the query. Wrapping is `table()`'s job because a
     * table name also needs the prefix; a column needs only to be provably an identifier.
     */
    private function column(string $name): string
    {
        if (1 !== preg_match('/^[a-z_]+$/', $name)) {
            throw new \InvalidArgumentException('Column name must be a bare identifier, got: ' . $name);
        }

        return $name;
    }

    /**
     * Render ids as a comma-separated list safe to splice into an `IN (…)` clause.
     *
     * Casting happens HERE, adjacent to the string that reaches SQL, not at the call site. The
     * post-condition makes the result digits-and-commas by construction rather than by convention;
     * it is unreachable, and that is the point — it turns an argument into an assertion.
     *
     * @param array<int|string> $ids
     */
    private function intList(array $ids): string
    {
        $list = implode(',', array_map('intval', $ids));

        if ('' !== $list && 1 !== preg_match('/^\d+(,\d+)*$/', $list)) {
            throw new \LogicException('intList produced a non-numeric list');
        }

        return $list;
    }
}
