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

    private function row(int $account, string $amount, ?int $to = null, string $interval = 'monthly', int $active = 1, ?string $end = null): array
    {
        return ['account_id' => $account, 'to_account_id' => $to, 'amount' => $amount, 'interval' => $interval, 'active' => $active, 'end_date' => $end];
    }

    public function testMonthlyTotalsWithoutAccountFilterIgnoreTransfers(): void
    {
        $rows = [
            $this->row(1, '3000.00'),
            $this->row(1, '-1200.00'),
            $this->row(1, '-120.00', null, 'yearly'),
            $this->row(1, '-500.00', 2),                    // Umbuchung bleibt im Haushalt
            $this->row(1, '-99.00', null, 'monthly', 0),    // pausiert
            $this->row(1, '-50.00', null, 'monthly', 1, '2024-01-31'), // beendet
        ];
        self::assertSame(['income' => 300000, 'expense' => -121000], RecurrenceService::monthlyTotals($rows, [], '2024-06-01'));
    }

    public function testMonthlyTotalsFollowAccountFilter(): void
    {
        $rows = [
            $this->row(1, '3000.00'),
            $this->row(2, '-400.00'),
            $this->row(1, '-500.00', 2), // von 1 nach 2
        ];
        // Nur Konto 2: Umbuchung kommt herein
        self::assertSame(['income' => 50000, 'expense' => -40000], RecurrenceService::monthlyTotals($rows, [2], '2024-06-01'));
        // Nur Konto 1: Umbuchung geht hinaus
        self::assertSame(['income' => 300000, 'expense' => -50000], RecurrenceService::monthlyTotals($rows, [1], '2024-06-01'));
        // Beide Konten: Umbuchung neutral
        self::assertSame(['income' => 300000, 'expense' => -40000], RecurrenceService::monthlyTotals($rows, [1, 2], '2024-06-01'));
    }

    public function testSamePayeeAnyRequiresMatchingPayee(): void
    {
        self::assertFalse(RecurrenceService::samePayeeAny(['Netflix'], '93 ERNSTINGS FAM.BAD SALZUF'));
        self::assertTrue(RecurrenceService::samePayeeAny(['Netflix'], 'NETFLIX INTERNATIONAL B.V.'));
        self::assertTrue(RecurrenceService::samePayeeAny(['Sparen', 'ING-DiBa AG'], 'ING-DiBa AG'));
        self::assertTrue(RecurrenceService::samePayeeAny([], 'irgendwer'));
    }

    public function testMatchesOccurrenceWithinWindow(): void
    {
        $tpl = $this->tpl('monthly', 1, '2024-01-01');
        self::assertTrue(RecurrenceService::matchesOccurrence($tpl, '2024-03-04'));
        self::assertTrue(RecurrenceService::matchesOccurrence($tpl, '2024-02-27'));
        self::assertFalse(RecurrenceService::matchesOccurrence($tpl, '2024-03-15'));
        self::assertFalse(RecurrenceService::matchesOccurrence($tpl, '2023-12-20'));
    }
}
