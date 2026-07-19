<?php
/**
 * Resolves active catalog languages and the default language id (spec §13, §16
 * item 6). `config_language` in oc_setting is a CODE (e.g. "en-gb"); the id is
 * resolved by joining oc_language.code — required in cron/CLI contexts where the
 * startup controller has not populated config_language_id.
 *
 * @package Spintax\Catalog
 */

declare(strict_types=1);

namespace Spintax\Catalog;

use Spintax\Db\DbInterface;
use Spintax\Db\SqlIdentifiers;

final class LanguageResolver
{
    use SqlIdentifiers;

    private DbInterface $db;
    private string $prefix;

    public function __construct(DbInterface $db, string $prefix)
    {
        $this->db = $db;
        $this->prefix = $prefix;
    }

    /**
     * @return array<int, string> language_id => code, active languages only.
     */
    public function activeLanguages(): array
    {
        $sql = sprintf(
            "SELECT language_id, code FROM %s WHERE status = '1' ORDER BY sort_order, language_id",
            $this->table('language')
        );

        $q = $this->db->query($sql);
        $out = array();
        foreach ($q->rows as $r) {
            $out[(int) $r['language_id']] = (string) $r['code'];
        }
        return $out;
    }

    public function defaultLanguageId(): int
    {
        $sql = sprintf(
            "SELECT `value` FROM %s WHERE `key` = 'config_language' AND store_id = '0'",
            $this->table('setting')
        );

        $s = $this->db->query($sql);
        $code = (string) ($s->row['value'] ?? '');
        if ('' === $code) {
            return 0;
        }
        $sql = sprintf(
            "SELECT language_id FROM %s WHERE code = '%s'",
            $this->table('language'),
            $this->db->escape($code)
        );

        $l = $this->db->query($sql);
        return (int) ($l->row['language_id'] ?? 0);
    }

    public function isInstalled(int $languageId): bool
    {
        return isset($this->activeLanguages()[$languageId]);
    }
}
