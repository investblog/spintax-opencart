<?php
/**
 * SEO-URL slug output mode (spec §9.5).
 *
 * `seo_keyword` targets must be URL-safe (lowercase, hyphenated, ASCII). The
 * engine's `post_process` deliberately emits prose (capitalised, re-spaced), so
 * keyword targets bypass it and route through this adapter instead. Cyrillic is
 * transliterated to latin because OpenCart's admin does not auto-transliterate —
 * critical for the RU/UA market. `HtmlSanitizer` is NOT applied to slug output
 * (this already emits URL-safe ASCII).
 *
 * @package Spintax\Slug
 */

declare(strict_types=1);

namespace Spintax\Slug;

final class SlugAdapter
{
    /**
     * Lowercase Cyrillic (Russian + Ukrainian) => latin. Input is lowercased
     * before transliteration, so only lowercase keys are needed.
     *
     * @var array<string, string>
     */
    private const TRANSLIT = array(
        // Russian
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch',
        'ъ' => '', 'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Ukrainian extras
        'ґ' => 'g', 'є' => 'ye', 'і' => 'i', 'ї' => 'yi',
    );

    /**
     * Render prose text into a URL-safe slug.
     *
     * @param string $text       Rendered text (pre-`post_process`).
     * @param int    $max_length Hard length cap (oc_seo_url.keyword is varchar(255)).
     * @return string Lowercase, hyphenated, ASCII slug (may be empty).
     */
    public function to_slug(string $text, int $max_length = 255): string
    {
        // Lowercase first (mb-aware), so the transliteration map needs only
        // lowercase keys and casing never survives into the slug.
        $text = mb_strtolower($text, 'UTF-8');

        // Transliterate Cyrillic -> latin.
        $text = strtr($text, self::TRANSLIT);

        // Any run of non [a-z0-9] becomes a single hyphen.
        $text = preg_replace('/[^a-z0-9]+/u', '-', $text);

        // Trim hyphens from the ends.
        $text = trim((string) $text, '-');

        // Enforce the length cap without leaving a trailing hyphen.
        if ($max_length > 0 && mb_strlen($text) > $max_length) {
            $text = rtrim(mb_substr($text, 0, $max_length), '-');
        }

        return $text;
    }
}
