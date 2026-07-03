<?php
/**
 * Minimal database seam matching OpenCart's `$this->db` shape (spec: OC3 mysqli
 * wrapper, no bound params). The engine writes via targeted direct SQL using
 * `escape()` — never through editProduct/editCategory (destructive full-rewrite).
 *
 * In OpenCart, wrap the registry `db` with OcDb; in tests, use MysqliDb.
 *
 * @package Spintax\Db
 */

declare(strict_types=1);

namespace Spintax\Db;

interface DbInterface
{
    /**
     * Run a query. SELECTs return a result object exposing ->num_rows (int),
     * ->row (first row, assoc) and ->rows (all rows, assoc), mirroring OpenCart.
     * Writes return true. Implementations throw on SQL error.
     *
     * @param string $sql
     * @return object|true
     */
    public function query(string $sql);

    /**
     * Escape a value for safe interpolation (no surrounding quotes added).
     */
    public function escape(string $value): string;

    /**
     * Rows affected by the last write — needed for compare-and-set locking.
     */
    public function affectedRows(): int;
}
