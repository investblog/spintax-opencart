<?php
/**
 * The opt-in storefront credit (spec §12.4). Pure, framework-agnostic so it is
 * unit-testable off the OC runtime; the catalog controller is a thin wrapper that
 * reads the setting and calls inject().
 *
 * Hard rules (spec §12.3–12.4, and the project's strategic goal): the credit is
 * **opt-in, default OFF, one-click removable, never required to function**, and —
 * when enabled — an honest, crawlable, dofollow `<a>` to spintax.net. No cloaking,
 * no hidden CSS, no forced/site-wide link scheme. A forced credit is explicitly
 * forbidden because it sabotages the adoption (массовость) the project depends on.
 *
 * @package Spintax\Catalog
 */

declare(strict_types=1);

namespace Spintax\Catalog;

final class StorefrontCredit
{
    /** The one clean dofollow storefront vector points at the brand hub. */
    public const URL = 'https://spintax.net/';
    public const ANCHOR = 'SEO by Spintax';

    /** The credit markup: a small, honest, crawlable dofollow link (no rel=nofollow). */
    public static function html(): string
    {
        return '<div class="spintax-seo-credit" style="text-align:center;font-size:11px;padding:6px 0;opacity:.75;">'
            . '<a href="' . self::URL . '">' . self::ANCHOR . '</a>'
            . '</div>';
    }

    /**
     * Return the footer output with the credit injected — ONLY when enabled. When
     * disabled the output is returned byte-for-byte unchanged (the extension is
     * fully functional with the credit off). Inserted just before the closing
     * </footer> when present, else appended.
     */
    public static function inject(bool $enabled, string $output): string
    {
        if (!$enabled) {
            return $output;
        }
        $credit = self::html();
        $pos = strripos($output, '</footer>');
        if (false !== $pos) {
            return substr($output, 0, $pos) . $credit . substr($output, $pos);
        }
        return $output . $credit;
    }
}
