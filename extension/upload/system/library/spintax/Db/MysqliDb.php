<?php
/**
 * mysqli-backed DbInterface for tests / CLI / cron contexts (where OpenCart's
 * registry `db` is not available). Returns OpenCart-shaped result objects so the
 * same query code runs under OC's `$this->db` and here unchanged.
 *
 * @package Spintax\Db
 */

declare(strict_types=1);

namespace Spintax\Db;

use mysqli;

final class MysqliDb implements DbInterface
{
    private mysqli $link;

    public function __construct(mysqli $link)
    {
        $this->link = $link;
    }

    public static function connect(string $host, string $user, string $pass, string $db, int $port = 3306): self
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $link = new mysqli($host, $user, $pass, $db, $port);
        if ($link->connect_errno) {
            throw new \RuntimeException('DB connect failed: ' . $link->connect_error);
        }
        $link->set_charset('utf8mb4');
        return new self($link);
    }

    /**
     * @return object|true
     */
    public function query(string $sql)
    {
        $result = $this->link->query($sql);
        if (false === $result) {
            throw new \RuntimeException('SQL error: ' . $this->link->error . ' — ' . $sql);
        }

        if (true === $result) {
            return true;
        }

        $rows = array();
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return (object) array(
            'num_rows' => count($rows),
            'row' => $rows[0] ?? array(),
            'rows' => $rows,
        );
    }

    public function escape(string $value): string
    {
        return $this->link->real_escape_string($value);
    }

    public function affectedRows(): int
    {
        return (int) $this->link->affected_rows;
    }

    public function link(): mysqli
    {
        return $this->link;
    }
}
