<?php
/**
 * Phase 1.0a gate — binding-id format + validation (spec §4.4/§4.6).
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Support\BindingId;

final class BindingIdTest extends TestCase
{
    public function test_generate_matches_format(): void
    {
        $id = BindingId::generate();
        $this->assertMatchesRegularExpression('/^bind_[a-z0-9]{6}$/', $id);
        $this->assertSame(11, strlen($id), 'must fit the char(11) PK');
    }

    public function test_generated_ids_validate(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertTrue(BindingId::isValid(BindingId::generate()));
        }
    }

    public function test_generated_ids_are_distinct(): void
    {
        $a = BindingId::generate();
        $b = BindingId::generate();
        // 24 bits of entropy — a same-value collision across two calls is negligible.
        $this->assertNotSame($a, $b);
    }

    /** @dataProvider invalidIds */
    public function test_invalid_ids_rejected($id): void
    {
        $this->assertFalse(BindingId::isValid($id));
    }

    public static function invalidIds(): array
    {
        return [
            'empty' => [''],
            'no-prefix' => ['abc123'],
            'wrong-prefix' => ['bond_abc123'],
            'too-short' => ['bind_abc12'],
            'too-long' => ['bind_abc1234'],
            'uppercase' => ['bind_ABC123'],
            'symbols' => ['bind_ab-123'],
            'not-string-int' => [12345],
            'not-string-null' => [null],
            'not-string-array' => [['bind_abc123']],
        ];
    }
}
