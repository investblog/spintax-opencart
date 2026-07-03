<?php
/**
 * Output HTML sanitizer seam (spec §9.2, §9.4).
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

interface HtmlSanitizerInterface
{
    /**
     * Filter rendered HTML down to the allow-listed subset (spec §9.4).
     *
     * @param string $html Rendered pre-sanitize HTML.
     * @return string Sanitized HTML safe to store in a catalog description field.
     */
    public function filter(string $html): string;
}
