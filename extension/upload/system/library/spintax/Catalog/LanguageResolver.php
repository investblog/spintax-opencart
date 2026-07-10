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

final class LanguageResolver
{
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
        $q = $this->db->query(
            "SELECT language_id, code FROM `" . $this->prefix . "language` WHERE status = '1' ORDER BY sort_order, language_id"
        );
        $out = array();
        foreach ($q->rows as $r) {
            $out[(int) $r['language_id']] = (string) $r['code'];
        }
        return $out;
    }

    public function defaultLanguageId(): int
    {
        $s = $this->db->query(
            "SELECT `value` FROM `" . $this->prefix . "setting` WHERE `key` = 'config_language' AND store_id = '0'"
        );
        $code = (string) ($s->row['value'] ?? '');
        if ('' === $code) {
            return 0;
        }
        $l = $this->db->query(
            "SELECT language_id FROM `" . $this->prefix . "language` WHERE code = '" . $this->db->escape($code) . "'"
        );
        return (int) ($l->row['language_id'] ?? 0);
    }

    public function isInstalled(int $languageId): bool
    {
        return isset($this->activeLanguages()[$languageId]);
    }
}
