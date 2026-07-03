<?php
/**
 * Phase 0.6 gate — SEO-URL slug adapter (spec §9.5).
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Slug\SlugAdapter;

final class SlugAdapterTest extends TestCase
{
    private SlugAdapter $slug;

    protected function setUp(): void
    {
        $this->slug = new SlugAdapter();
    }

    public function test_lowercases_and_hyphenates_ascii(): void
    {
        $this->assertSame('buy-a-cheap-phone', $this->slug->to_slug('Buy a Cheap Phone'));
    }

    public function test_collapses_punctuation_and_whitespace(): void
    {
        $this->assertSame('a-b-c', $this->slug->to_slug("a,  b --- c!!!"));
    }

    public function test_trims_leading_trailing_separators(): void
    {
        $this->assertSame('phone', $this->slug->to_slug('  ...phone...  '));
    }

    public function test_transliterates_russian_cyrillic(): void
    {
        $this->assertSame('kupit-telefon-v-moskve', $this->slug->to_slug('Купить телефон в Москве'));
    }

    public function test_transliterates_special_russian_letters(): void
    {
        // ё->yo, ж->zh, ч->ch, ш->sh, щ->shch, ц->ts, ю->yu, я->ya, ь/ъ dropped
        $this->assertSame('yozh-chay-shchit', $this->slug->to_slug('Ёж чай щит'));
        $this->assertSame('podezd', $this->slug->to_slug('подъезд'));
    }

    public function test_transliterates_ukrainian_letters(): void
    {
        // ї->yi, є->ye, і->i, ґ->g
        $this->assertSame('yizha-ye-i-g', $this->slug->to_slug('їжа є і ґ'));
    }

    public function test_mixed_latin_and_cyrillic(): void
    {
        $this->assertSame('iphone-15-pro', $this->slug->to_slug('iPhone 15 Pro'));
        $this->assertSame('kupit-iphone', $this->slug->to_slug('Купить iPhone'));
    }

    public function test_length_cap_without_trailing_hyphen(): void
    {
        // 'one-two-three-four-five' truncated at 7 chars => 'one-two' (no trailing hyphen).
        $out = $this->slug->to_slug('one two three four five', 7);
        $this->assertLessThanOrEqual(7, mb_strlen($out));
        $this->assertSame('one-two', $out);
    }

    public function test_empty_on_all_punctuation(): void
    {
        $this->assertSame('', $this->slug->to_slug('!!! ??? ...'));
    }
}
