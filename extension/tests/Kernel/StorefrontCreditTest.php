<?php
/**
 * Opt-in storefront credit (spec §12.4) — unit tests for the pure injector.
 * Enforces the hard rules: default OFF (disabled = output untouched), and when
 * enabled a single honest, crawlable, DOFOLLOW link to spintax.net with no
 * cloaking / hidden CSS / rel=nofollow.
 *
 * @package Spintax\Tests\Kernel
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Catalog\StorefrontCredit;

final class StorefrontCreditTest extends TestCase
{
    public function test_disabled_returns_output_unchanged(): void
    {
        $footer = '<footer><p>&copy; Shop</p></footer>';
        $this->assertSame($footer, StorefrontCredit::inject(false, $footer), 'default OFF: never touch the storefront');
    }

    public function test_enabled_inserts_before_closing_footer(): void
    {
        $footer = '<footer><p>&copy; Shop</p></footer>';
        $out = StorefrontCredit::inject(true, $footer);
        $this->assertNotSame($footer, $out);
        $this->assertStringContainsString(StorefrontCredit::html(), $out);
        // Credit sits INSIDE the footer element (before the closing tag).
        $this->assertLessThan(strripos($out, '</footer>'), strpos($out, 'spintax-seo-credit'));
    }

    public function test_enabled_appends_when_no_footer_tag(): void
    {
        $footer = '<div class="footer">shop</div>';
        $out = StorefrontCredit::inject(true, $footer);
        $this->assertStringStartsWith($footer, $out);
        $this->assertStringEndsWith(StorefrontCredit::html(), $out);
    }

    public function test_credit_is_an_honest_dofollow_link(): void
    {
        $html = StorefrontCredit::html();
        $this->assertStringContainsString('href="https://spintax.net/"', $html);
        $this->assertStringContainsString('SEO by Spintax', $html);
        // Dofollow: the one clean storefront vector must NOT carry rel=nofollow.
        $this->assertStringNotContainsStringIgnoringCase('nofollow', $html);
        // No cloaking / hidden link / injected script.
        $this->assertStringNotContainsStringIgnoringCase('display:none', $html);
        $this->assertStringNotContainsStringIgnoringCase('visibility:hidden', $html);
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);
        // Exactly one anchor — no extra injected links.
        $this->assertSame(1, substr_count($html, '<a '));
    }
}
