<?php
/**
 * Phase 0.7 gate — public entry point (Engine facade + global helpers),
 * end-to-end across all four output modes, including a ru-RU 3-form plural.
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Engine\Parser;
use Spintax\Engine;

final class EngineIntegrationTest extends TestCase
{
    private function engine(): Engine
    {
        // Deterministic (always-min) RNG for exact assertions.
        return new Engine(new Parser(static fn(int $min, int $max): int => $min));
    }

    public function test_render_html_body_with_vars_and_enum(): void
    {
        $out = $this->engine()->render('{Buy|Purchase} the <strong>%product%</strong> today', array('product' => 'iPhone'));
        $this->assertSame('Buy the <strong>iPhone</strong> today', $out);
    }

    public function test_render_sanitizes_html(): void
    {
        $out = $this->engine()->render('<script>evil()</script><p>%msg%</p>', array('msg' => 'Safe copy'));
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('<p>Safe copy</p>', $out);
    }

    public function test_render_plain_strips_tags_for_meta(): void
    {
        $out = $this->engine()->renderPlain('<p>{Best|Top} deal on %product%</p>', array('product' => 'phones'));
        $this->assertSame('Best deal on phones', $out);
    }

    public function test_render_slug_transliterates_for_seo_keyword(): void
    {
        $out = $this->engine()->renderSlug('Купить %product%', array('product' => 'iPhone 15'));
        $this->assertSame('kupit-iphone-15', $out);
    }

    public function test_ru_three_form_plural_via_locale(): void
    {
        $eng = $this->engine();
        $tpl = '{plural %n%: товар|товара|товаров}';
        $this->assertSame('Товар', $eng->renderPlain($tpl, array('n' => '1'), 'ru-RU'));
        $this->assertSame('Товара', $eng->renderPlain($tpl, array('n' => '2'), 'ru-RU'));
        $this->assertSame('Товаров', $eng->renderPlain($tpl, array('n' => '5'), 'ru-RU'));
    }

    public function test_conditional_end_to_end(): void
    {
        $eng = $this->engine();
        $tpl = '{?sale?Sale now|Regular price}';
        $this->assertSame('Sale now', $eng->renderPlain($tpl, array('sale' => '1')));
        $this->assertSame('Regular price', $eng->renderPlain($tpl, array('sale' => '')));
    }

    public function test_pre_sanitize_matches_render_minus_sanitizer(): void
    {
        $eng = $this->engine();
        $src = '{Hello|Hi} world';
        // For tag-free content, pre-sanitize == sanitized.
        $this->assertSame($eng->renderPreSanitize($src), $eng->render($src));
    }

    // --- global helpers ------------------------------------------------------

    public function test_global_spintax_render_exists_and_renders(): void
    {
        $this->assertTrue(function_exists('spintax_render'));
        $out = spintax_render('{A|B}');
        $this->assertContains($out, array('A', 'B'));
    }

    public function test_global_slug_helper(): void
    {
        $this->assertTrue(function_exists('spintax_render_slug'));
        $this->assertSame('kupit-telefon', spintax_render_slug('Купить телефон'));
    }

    public function test_global_plain_helper(): void
    {
        $this->assertTrue(function_exists('spintax_render_plain'));
        $this->assertSame('Hello world', spintax_render_plain('<p>Hello world</p>'));
    }
}
