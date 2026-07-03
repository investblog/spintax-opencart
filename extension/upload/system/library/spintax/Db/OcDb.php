<?php
/**
 * DbInterface adapter over OpenCart's registry `db` (`$this->db`). OpenCart's DB
 * object already exposes query()/escape() with the exact shape DbInterface
 * documents (SELECT → object with ->num_rows/->row/->rows; write → true), so this
 * is a thin pass-through that lets the same engine code run under OC and under
 * MysqliDb in tests/CLI/cron.
 *
 * @package Spintax\Db
 */

declare(strict_types=1);

namespace Spintax\Db;

final class OcDb implements DbInterface
{
    /** @var object OpenCart DB wrapper (\DB). */
    private object $db;

    public function __construct(object $db)
    {
        $this->db = $db;
    }

    /**
     * @return object|true
     */
    public function query(string $sql)
    {
        return $this->db->query($sql);
    }

    public function escape(string $value): string
    {
        return $this->db->escape($value);
    }

    public function affectedRows(): int
    {
        // OpenCart's DB wrapper exposes the affected-row count as ->countAffected().
        return (int) $this->db->countAffected();
    }
}
