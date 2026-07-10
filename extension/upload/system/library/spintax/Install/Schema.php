<?php
/**
 * Extension schema — the five `spintax_*` tables (spec §4.4).
 *
 * OpenCart 3.0.x does NOT auto-run install.sql, so these are created by the
 * controller `install()` via `$this->db->query(...)`. The `DB_PREFIX` is
 * concatenated at runtime — NEVER hardcode `oc_`. Uninstall is non-destructive
 * by default (spec §11.3): drop only when the admin opts into "delete all data".
 *
 * @package Spintax\Install
 */

declare(strict_types=1);

namespace Spintax\Install;

final class Schema
{
    /** Bare table names (unprefixed), in create order. */
    public const TABLES = array(
        'spintax_binding',
        'spintax_template',
        'spintax_source',
        'spintax_signature',
        'spintax_walk',
        'spintax_log',
    );

    /**
     * @param string $prefix DB_PREFIX (e.g. "oc_").
     * @return string[] Fully-qualified table names.
     */
    public static function tableNames(string $prefix): array
    {
        return array_map(static fn(string $t): string => $prefix . $t, self::TABLES);
    }

    /**
     * CREATE TABLE statements (idempotent via IF NOT EXISTS), prefix-substituted.
     *
     * @param string $prefix DB_PREFIX.
     * @return string[] One CREATE statement per table.
     */
    public static function createStatements(string $prefix): array
    {
        $ddl = array();

        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_binding` (
  `binding_id`            char(11)     NOT NULL,
  `entity_type`           varchar(20)  NOT NULL,
  `target_kind`           varchar(20)  NOT NULL,
  `target_column`         varchar(64)  NOT NULL DEFAULT '',
  `attribute_id`          int(11)      NOT NULL DEFAULT 0,
  `stable_id`             varchar(64)  NOT NULL DEFAULT '',
  `language_scope`        varchar(255) NOT NULL DEFAULT 'ALL_ACTIVE',
  `store_scope`           varchar(255) NOT NULL DEFAULT 'ALL',
  `source_mode`           varchar(20)  NOT NULL,
  `template_id`           int(11)      NOT NULL DEFAULT 0,
  `trigger_on_save`       tinyint(1)   NOT NULL DEFAULT 1,
  `cadence`               varchar(20)  NOT NULL DEFAULT 'off',
  `auto_seed_empty`       tinyint(1)   NOT NULL DEFAULT 1,
  `regenerate_on_save`    tinyint(1)   NOT NULL DEFAULT 0,
  `preserve_manual_edits` tinyint(1)   NOT NULL DEFAULT 1,
  `clear_on_empty`        tinyint(1)   NOT NULL DEFAULT 0,
  `seo_disambiguate`      tinyint(1)   NOT NULL DEFAULT 0,
  `chunk_size`            int(11)      NOT NULL DEFAULT 20,
  `cache_version`         int(11)      NOT NULL DEFAULT 1,
  `status`                tinyint(1)   NOT NULL DEFAULT 1,
  `scope_enabled_only`    tinyint(1)   NOT NULL DEFAULT 1,
  `date_added`            datetime     NOT NULL,
  `date_modified`         datetime     NOT NULL,
  PRIMARY KEY (`binding_id`),
  KEY `entity_lookup` (`entity_type`, `target_kind`),
  UNIQUE KEY `uniq_binding_target`
    (`entity_type`, `target_kind`, `target_column`, `attribute_id`, `language_scope`(64), `store_scope`(64))
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_template` (
  `template_id`   int(11)      NOT NULL AUTO_INCREMENT,
  `name`          varchar(255) NOT NULL DEFAULT '',
  `source`        mediumtext   NOT NULL,
  `locale`        varchar(10)  NOT NULL DEFAULT '',
  `date_added`    datetime     NOT NULL,
  `date_modified` datetime     NOT NULL,
  PRIMARY KEY (`template_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_source` (
  `source_id`     int(11)      NOT NULL AUTO_INCREMENT,
  `entity_type`   varchar(20)  NOT NULL,
  `entity_id`     int(11)      NOT NULL,
  `language_id`   int(11)      NOT NULL DEFAULT 0,
  `source`        mediumtext   NOT NULL,
  `date_added`    datetime     NOT NULL,
  `date_modified` datetime     NOT NULL,
  PRIMARY KEY (`source_id`),
  UNIQUE KEY `uniq_entity_source` (`entity_type`, `entity_id`, `language_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_signature` (
  `binding_id`    char(11)     NOT NULL,
  `entity_id`     int(11)      NOT NULL,
  `language_id`   int(11)      NOT NULL,
  `store_id`      int(11)      NOT NULL DEFAULT -1,
  `signature`     char(40)     NOT NULL,
  `date_modified` datetime     NOT NULL,
  PRIMARY KEY (`binding_id`, `entity_id`, `language_id`, `store_id`),
  KEY `entity_purge` (`entity_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_walk` (
  `binding_id`           char(11)   NOT NULL,
  `cursor_offset`        int(11)    NOT NULL DEFAULT 0,
  `total`                int(11)    NOT NULL DEFAULT 0,
  `processed`            int(11)    NOT NULL DEFAULT 0,
  `lock_ts`              int(10) unsigned NOT NULL DEFAULT 0,
  `walk_failed`          tinyint(1) NOT NULL DEFAULT 0,
  `cache_version`        int(11)    NOT NULL DEFAULT 1,
  `last_applied_version` int(11)    NOT NULL DEFAULT 0,
  `last_run`             int(10) unsigned NOT NULL DEFAULT 0,
  `snapshot_token`       char(40)   NOT NULL DEFAULT '',
  `date_modified`        datetime   NOT NULL,
  PRIMARY KEY (`binding_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        // Activity log (§15 Logs page): one row per apply event across the three
        // triggers (save / bulk / cron). Bounded by pruning to the newest N.
        $ddl[] = "CREATE TABLE IF NOT EXISTS `" . $prefix . "spintax_log` (
  `log_id`      int(11)      NOT NULL AUTO_INCREMENT,
  `binding_id`  char(11)     NOT NULL DEFAULT '',
  `origin`      varchar(10)  NOT NULL DEFAULT '',
  `entity_id`   int(11)      NOT NULL DEFAULT 0,
  `written`     int(11)      NOT NULL DEFAULT 0,
  `skipped`     int(11)      NOT NULL DEFAULT 0,
  `blocked`     int(11)      NOT NULL DEFAULT 0,
  `note`        varchar(255) NOT NULL DEFAULT '',
  `date_added`  datetime     NOT NULL,
  PRIMARY KEY (`log_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8";

        return $ddl;
    }

    /**
     * DROP statements for the opt-in "delete all Spintax data" uninstall path.
     *
     * @param string $prefix DB_PREFIX.
     * @return string[]
     */
    public static function dropStatements(string $prefix): array
    {
        // Reverse create order (no FKs declared, but keep it tidy).
        return array_map(
            static fn(string $t): string => "DROP TABLE IF EXISTS `" . $prefix . $t . "`",
            array_reverse(self::TABLES)
        );
    }
}
