<?php
/**
 * Phase 1.4 gate — dry-run snapshot token (spec §7.1). The token must be
 * deterministic, order-independent in the language set, and change when ANY
 * config/scope input changes (so Apply can detect a stale snapshot).
 */

declare(strict_types=1);

namespace Spintax\Tests\Kernel;

use PHPUnit\Framework\TestCase;
use Spintax\Core\Binding\DryRunToken;

final class DryRunTokenTest extends TestCase
{
    private function base(): array
    {
        return array('bind_a1b2c3', '2026-07-03 14:02:00', 4, '2026-07-01 09:00:00', 3, array(1, 2), 'ALL');
    }

    private function token(array $a): string
    {
        return DryRunToken::compute($a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6]);
    }

    public function test_is_deterministic(): void
    {
        $this->assertSame($this->token($this->base()), $this->token($this->base()));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $this->token($this->base()));
    }

    public function test_language_order_does_not_matter(): void
    {
        $a = $this->base();
        $b = $this->base();
        $b[5] = array(2, 1); // reversed
        $this->assertSame($this->token($a), $this->token($b));
    }

    /** @dataProvider mutations */
    public function test_any_input_change_changes_token(int $index, $newValue): void
    {
        $a = $this->base();
        $b = $this->base();
        $b[$index] = $newValue;
        $this->assertNotSame($this->token($a), $this->token($b), "mutating field {$index} must change the token");
    }

    public static function mutations(): array
    {
        return [
            'binding-modified (config edit)' => [1, '2026-07-03 15:00:00'],
            'template-id' => [2, 5],
            'template-modified (template edit)' => [3, '2026-07-02 10:00:00'],
            'cache-version bump' => [4, 4],
            'active-languages (added lang)' => [5, [1, 2, 3]],
            'store-scope' => [6, '0,1'],
        ];
    }
}
