<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecurrenceService;
use PHPUnit\Framework\TestCase;

final class RecurrenceServiceTest extends TestCase
{
    private function tpl(string $interval, int $day, string $start, ?string $end = null): array
    {
        return ['interval' => $interval, 'day_of_month' => $day, 'start_date' => $start, 'end_date' => $end];
    }

    public function testMonthlyEndOfMonthIsClamped(): void
    {
        $d = RecurrenceService::occurrences($this->tpl('monthly', 31, '2024-01-31'), '2024-01-01', '2024-05-31');
        self::assertSame(['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30', '2024-05-31'], $d);
    }

    public function testQuarterlyAndWindow(): void
    {
        $d = RecurrenceService::occurrences($this->tpl('quarterly', 15, '2023-02-15'), '2024-01-01', '2024-12-31');
        self::assertSame(['2024-02-15', '2024-05-15', '2024-08-15', '2024-11-15'], $d);
    }

    public function testYearlyWithEndDate(): void
    {
        $d = RecurrenceService::occurrences($this->tpl('yearly', 1, '2020-03-01', '2023-03-01'), '2019-01-01', '2030-12-31');
        self::assertSame(['2020-03-01', '2021-03-01', '2022-03-01', '2023-03-01'], $d);
    }

    public function testNothingBeforeStart(): void
    {
        self::assertSame([], RecurrenceService::occurrences($this->tpl('monthly', 1, '2025-01-01'), '2024-01-01', '2024-12-31'));
    }

    public function testNextOccurrenceAndMonthlyAmount(): void
    {
        self::assertSame('2024-07-01', RecurrenceService::nextOccurrence($this->tpl('halfyearly', 1, '2024-01-01'), '2024-01-02'));
        self::assertEqualsWithDelta(-10.0, RecurrenceService::monthlyAmount('-120.00', 'yearly'), 0.001);
    }
}
