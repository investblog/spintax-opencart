<?php
/**
 * Phase 0.6 gate — HtmlSanitizer allow-list (spec §9.4).
 *
 * Tested SEPARATELY from the kernel byte-identity gate: this sanitizer differs
 * from WP `wp_kses_post` by design, so it is validated against its own pinned
 * allow-list, not against WP output.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Shim\HtmlSanitizer;

final class HtmlSanitizerTest extends TestCase
{
    private HtmlSanitizer $san;

    protected function setUp(): void
    {
        $this->san = new HtmlSanitizer();
    }

    public function test_allowed_formatting_tags_survive(): void
    {
        $html = '<p>Hello <strong>bold</strong> and <em>italic</em> and <a href="https://301.st">link</a>.</p>';
        $this->assertSame($html, $this->san->filter($html));
    }

    public function test_lists_and_headings_survive(): void
    {
        $html = '<h2>Title</h2><ul><li>one</li><li>two</li></ul>';
        $this->assertSame($html, $this->san->filter($html));
    }

    public function test_script_removed_with_content(): void
    {
        $out = $this->san->filter('<p>Safe</p><script>alert(1)</script>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert(1)', $out);
        $this->assertStringContainsString('<p>Safe</p>', $out);
    }

    /** @dataProvider dangerousTags */
    public function test_dangerous_tags_removed(string $html, string $needle): void
    {
        $this->assertStringNotContainsString($needle, $this->san->filter($html));
    }

    public static function dangerousTags(): array
    {
        return [
            'iframe' => ['<iframe src="https://evil.test"></iframe>ok', '<iframe'],
            'object' => ['<object data="x"></object>ok', '<object'],
            'embed' => ['<embed src="x">ok', '<embed'],
            'form' => ['<form action="x"><input name="y"></form>ok', '<form'],
            'input' => ['<input value="x">ok', '<input'],
            'svg' => ['<svg onload="x()"></svg>ok', '<svg'],
        ];
    }

    public function test_event_handlers_and_id_stripped(): void
    {
        $out = $this->san->filter('<p id="x" onclick="steal()" class="keep">hi</p>');
        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringNotContainsString('id=', $out);
        $this->assertStringContainsString('class="keep"', $out);
    }

    public function test_h1_is_unwrapped_but_text_survives(): void
    {
        // h1 is deliberately excluded (theme owns the page <h1>).
        $out = $this->san->filter('<h1>Heading text</h1>');
        $this->assertStringNotContainsString('<h1', $out);
        $this->assertStringContainsString('Heading text', $out);
    }

    public function test_unknown_tag_unwrapped_preserving_children(): void
    {
        $out = $this->san->filter('<div><font color="red">kept text</font></div>');
        $this->assertStringNotContainsString('<div', $out);
        $this->assertStringNotContainsString('<font', $out);
        $this->assertStringContainsString('kept text', $out);
    }

    /** @dataProvider urlCases */
    public function test_href_scheme_allow_list(string $href, bool $survives): void
    {
        $out = $this->san->filter('<a href="' . $href . '">x</a>');
        if ($survives) {
            $this->assertStringContainsString('href=', $out, "{$href} should survive");
        } else {
            $this->assertStringNotContainsString('href=', $out, "{$href} should be stripped");
        }
    }

    public static function urlCases(): array
    {
        return [
            'https' => ['https://301.st/page', true],
            'http' => ['http://example.com', true],
            'mailto' => ['mailto:hi@301.st', true],
            'root-relative' => ['/catalog/item', true],
            'javascript' => ['javascript:alert(1)', false],
            'data' => ['data:text/html,<script>', false],
            'vbscript' => ['vbscript:msgbox(1)', false],
            // §9.4 rule 1: only the four survivor forms above are allowed.
            'protocol-relative' => ['//evil.test/x', false],
            'bare-relative' => ['relative/path', false],
            'anchor' => ['#section', false],
            'tel-scheme' => ['tel:+15551234', false],
        ];
    }

    public function test_img_data_uri_src_stripped_but_tag_kept(): void
    {
        $out = $this->san->filter('<img src="data:image/png;base64,AAAA" alt="x">');
        $this->assertStringContainsString('<img', $out);
        $this->assertStringNotContainsString('src=', $out);
        $this->assertStringContainsString('alt="x"', $out);
    }

    public function test_rel_passed_through_verbatim(): void
    {
        $out = $this->san->filter('<a href="https://x.test" rel="nofollow noopener">x</a>');
        $this->assertStringContainsString('rel="nofollow noopener"', $out);
    }

    public function test_style_property_whitelist(): void
    {
        $out = $this->san->filter('<p style="color: red; position: absolute; text-align: center">x</p>');
        $this->assertStringContainsString('color: red', $out);
        $this->assertStringContainsString('text-align: center', $out);
        $this->assertStringNotContainsString('position', $out);
    }

    public function test_style_can_be_disabled_entirely(): void
    {
        $san = new HtmlSanitizer(false);
        $out = $san->filter('<p style="color: red">x</p>');
        $this->assertStringNotContainsString('style', $out);
        $this->assertStringContainsString('x', $out);
    }

    public function test_cyrillic_content_preserved(): void
    {
        $out = $this->san->filter('<p>Купить телефон</p>');
        $this->assertStringContainsString('Купить телефон', html_entity_decode($out, ENT_QUOTES, 'UTF-8'));
    }
}
