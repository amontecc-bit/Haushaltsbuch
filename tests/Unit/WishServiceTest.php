<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\WishService;
use PHPUnit\Framework\TestCase;

final class WishServiceTest extends TestCase
{
    private function wish(array $over = []): array
    {
        return $over + [
            'id' => 1, 'status' => 'open', 'price' => '1000.00', 'wanted_by' => null,
            'plan_amount' => '100.00', 'plan_start' => '2026-01-31', 'plan_saved' => '0.00',
        ];
    }

    public function testRateDatesAreClampedToMonthEnd(): void
    {
        self::assertSame('2026-01-31', WishService::rateDate('2026-01-31', 0));
        self::assertSame('2026-02-28', WishService::rateDate('2026-01-31', 1));
        self::assertSame('2026-03-31', WishService::rateDate('2026-01-31', 2));
        self::assertSame('2027-01-31', WishService::rateDate('2026-01-31', 12));
    }

    public function testRatesUntilCountsInclusive(): void
    {
        self::assertSame(0, WishService::ratesUntil('2026-01-15', '2026-01-14'));
        self::assertSame(1, WishService::ratesUntil('2026-01-15', '2026-01-15'));
        self::assertSame(1, WishService::ratesUntil('2026-01-15', '2026-02-14'));
        self::assertSame(2, WishService::ratesUntil('2026-01-15', '2026-02-15'));
        self::assertSame(2, WishService::ratesUntil('2026-01-31', '2026-02-28'));
    }

    public function testPlanSavedAndReachDate(): void
    {
        // 300 Startguthaben + 100/Monat → 7 Raten → 7. Rate am 31.07.2026
        $p = WishService::plan($this->wish(['plan_saved' => '300.00']), '2026-03-15');
        self::assertSame(300_00 + 2 * 100_00, $p['saved']); // Raten 31.01. und 28.02.
        self::assertSame('2026-07-31', $p['reach_date']);
        self::assertSame('2026-03-31', $p['next_rate']);
        self::assertSame(5, $p['rates_left']);
        self::assertTrue($p['running']);
        self::assertFalse($p['reached']);
        self::assertEqualsWithDelta(50.0, $p['progress'], 0.001);
    }

    public function testPlanStopsAtPriceAndLastRateIsPartial(): void
    {
        $w = $this->wish(['price' => '250.00']);
        $p = WishService::plan($w, '2027-01-01');
        self::assertTrue($p['reached']);
        self::assertFalse($p['running']);
        self::assertSame(250_00, $p['saved']);
        self::assertSame(0, $p['remaining']);

        $s = WishService::schedule($w, '2026-01-01');
        self::assertSame([100_00, 100_00, 50_00], array_column($s, 'rate'));
        self::assertSame('2026-03-31', end($s)['date']);
    }

    public function testFuturePlanCountsOnlyStartBalance(): void
    {
        $p = WishService::plan($this->wish(['plan_saved' => '50.00', 'plan_start' => '2026-12-01']), '2026-11-01');
        self::assertSame(50_00, $p['saved']);
        self::assertSame('2026-12-01', $p['next_rate']);
    }

    public function testWishDateTooEarlySuggestsRate(): void
    {
        // bis 30.04. noch Raten am 31.03. und 30.04. → 800 € offen / 2 = 400 €
        $p = WishService::plan($this->wish(['wanted_by' => '2026-04-30']), '2026-03-01');
        self::assertTrue($p['late']);
        self::assertSame(400_00, $p['rate_for_wish_date']);
        self::assertFalse(WishService::plan($this->wish(['wanted_by' => '2026-12-31']), '2026-03-01')['late']);
    }

    public function testWithoutPlanOrPrice(): void
    {
        $p = WishService::plan($this->wish(['plan_amount' => null, 'plan_saved' => '0']), '2026-03-01');
        self::assertFalse($p['has_plan']);
        self::assertFalse($p['running']);
        self::assertNull($p['reach_date']);

        $p = WishService::plan($this->wish(['price' => null]), '2026-03-01');
        self::assertTrue($p['running']);
        self::assertNull($p['progress']);
        self::assertSame(200_00, $p['saved']);
        self::assertCount(12, WishService::schedule($this->wish(['price' => null]), '2026-03-01', 12));
    }

    public function testFulfilledWishDoesNotRun(): void
    {
        self::assertFalse(WishService::plan($this->wish(['status' => 'fulfilled']), '2026-03-01')['running']);
    }

    public function testRecurringMonthlyAndIncomeAccount(): void
    {
        $base = ['account_id' => 1, 'to_account_id' => null, 'counterpart_account_id' => null, 'interval' => 'monthly'];
        self::assertSame(200_00, WishService::recurringMonthly(['amount' => '-200.00'] + $base));
        self::assertSame(100_00, WishService::recurringMonthly(['amount' => '-1200.00', 'interval' => 'yearly'] + $base));

        self::assertSame(2, WishService::incomeAccountId(['amount' => '-200.00', 'to_account_id' => 2] + $base));
        self::assertSame(1, WishService::incomeAccountId(['amount' => '500.00'] + $base));
        self::assertSame(3, WishService::incomeAccountId(['amount' => '-50.00', 'counterpart_account_id' => 3] + $base));
        self::assertNull(WishService::incomeAccountId(['amount' => '-50.00'] + $base));
    }

    public function testUsageWarnsWhenPlansExceedPosten(): void
    {
        $rec = ['amount' => '-200.00', 'interval' => 'monthly', 'active' => 1, 'end_date' => null];
        $u = WishService::usage($rec, [150_00, 80_00], '2026-03-01');
        self::assertSame(['capacity' => 200_00, 'used' => 230_00, 'free' => -30_00, 'over' => true], $u);
        self::assertFalse(WishService::usage($rec, [150_00], '2026-03-01')['over']);
        // pausierter oder beendeter Posten deckt nichts mehr
        self::assertTrue(WishService::usage(['active' => 0] + $rec, [10_00], '2026-03-01')['over']);
        self::assertTrue(WishService::usage(['end_date' => '2026-02-28'] + $rec, [10_00], '2026-03-01')['over']);
    }

    public function testAllocateCoversInOrder(): void
    {
        self::assertSame(
            [5 => ['covered' => 300_00, 'missing' => 0], 2 => ['covered' => 100_00, 'missing' => 100_00], 9 => ['covered' => 0, 'missing' => 50_00]],
            WishService::allocate([5 => 300_00, 2 => 200_00, 9 => 50_00], 400_00)
        );
        self::assertSame([1 => ['covered' => 0, 'missing' => 10_00]], WishService::allocate([1 => 10_00], -500_00));
    }
}
