<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\LoanCalculator;
use PHPUnit\Framework\TestCase;

final class LoanCalculatorTest extends TestCase
{
    private function loan(array $over = []): array
    {
        return $over + [
            'principal' => '100000.00', 'payout_date' => '2024-01-01', 'interest_rate' => '3.000', 'loan_type' => 'annuity',
            'monthly_payment' => null, 'initial_repayment_rate' => '2.000', 'first_payment_date' => '2024-02-01', 'fixed_until' => '2034-01-31',
        ];
    }

    public function testAnnuityMatchesClosedFormula(): void
    {
        $c = new LoanCalculator($this->loan());
        self::assertSame(41667, LoanCalculator::initialPayment($this->loan()));
        // B_n = P·qⁿ − R·(qⁿ−1)/(q−1) mit q = 1 + 3 %/12, R = 416,67 €, n = 120
        $q = 1 + 0.03 / 12;
        $expected = 100000 * $q ** 120 - 416.67 * ($q ** 120 - 1) / ($q - 1);
        self::assertEqualsWithDelta($expected, $c->balanceAt('2034-01-01'), 0.05);
        $s = $c->schedule();
        self::assertSame(25000, $s[0]['interest']);
        self::assertSame(0, end($s)['balance']);
    }

    public function testBalanceBeforePayoutAndBetweenPayments(): void
    {
        $c = new LoanCalculator($this->loan());
        self::assertSame(0.0, $c->balanceAt('2023-12-31'));
        self::assertSame(100000.0, $c->balanceAt('2024-01-15'));
        self::assertSame(99833.33, $c->balanceAt('2024-02-15'));
    }

    public function testSpecialPaymentShortensTerm(): void
    {
        $base = new LoanCalculator($this->loan());
        $with = new LoanCalculator($this->loan(), [['payment_date' => '2025-06-15', 'amount' => '10000.00']]);
        self::assertLessThan(count($base->schedule()), count($with->schedule()));
        self::assertEqualsWithDelta($base->balanceAt('2025-06-10') - 10000, $with->balanceAt('2025-06-20'), 0.01);
        self::assertLessThan($base->summary()['total_interest'], $with->summary()['total_interest']);
    }

    public function testRateChange(): void
    {
        $c = new LoanCalculator($this->loan(), [], [['valid_from' => '2034-02-01', 'interest_rate' => '5.0', 'monthly_payment' => '600.00']]);
        $row = array_values(array_filter($c->schedule(), fn ($r) => $r['date'] === '2034-02-01'))[0];
        self::assertSame(60000, $row['payment']);
        self::assertSame(5.0, $row['rate']);
    }

    public function testFixedPrincipalLoan(): void
    {
        $c = new LoanCalculator($this->loan(['loan_type' => 'fixed_principal', 'monthly_payment' => '1000.00', 'first_payment_date' => '2024-02-01']));
        $s = $c->schedule();
        self::assertCount(100, $s);
        self::assertSame(100000, $s[0]['principal']);
        self::assertGreaterThan($s[1]['payment'], $s[0]['payment'], 'Rate sinkt beim Tilgungsdarlehen');
    }

    public function testPartialFirstPeriod(): void
    {
        self::assertSame(45, LoanCalculator::days360('2024-03-15', '2024-04-30'));
    }
}
