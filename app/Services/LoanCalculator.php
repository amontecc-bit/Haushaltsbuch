<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;
use DateTimeImmutable;

/**
 * Tilgungsplan für Annuitäten- und Tilgungsdarlehen (monatliche Raten, Zinsmethode 30/360).
 *
 * - Annuität: gleichbleibende Rate (Zins + Tilgung). Ist keine Rate angegeben, wird sie aus
 *   Sollzins + anfänglicher Tilgung berechnet: Kredit × (Zins + Tilgung) / 12.
 * - Tilgungsdarlehen: gleichbleibende Tilgung, Zinsen kommen hinzu (fallende Rate).
 * - Erste Zinsperiode: von Auszahlung bis zur ersten Rate (taggenau 30/360).
 * - Sondertilgungen mindern die Restschuld zum Zeitpunkt ihrer Zahlung
 *   (Zinsen der laufenden Periode werden auf die Restschuld am Periodenbeginn berechnet).
 * - Änderungen (Zins/Rate) gelten ab der ersten Rate am oder nach dem Stichtag.
 */
final class LoanCalculator
{
    private const MAX_PERIODS = 720;

    private ?array $schedule = null;

    /**
     * @param array $loan Zeile aus `loans`
     * @param array $specials Zeilen aus `loan_special_payments`
     * @param array $changes Zeilen aus `loan_rate_changes`
     */
    public function __construct(private readonly array $loan, private readonly array $specials = [], private readonly array $changes = [])
    {
    }

    /** Anfängliche Monatsrate in Cent */
    public static function initialPayment(array $loan): int
    {
        $principal = Money::toCents($loan['principal']);
        if (!empty($loan['monthly_payment']) && (float) $loan['monthly_payment'] > 0) {
            return Money::toCents($loan['monthly_payment']);
        }
        $rate = (float) $loan['interest_rate'] / 100;
        $repay = (float) ($loan['initial_repayment_rate'] ?? 0) / 100;
        if (($loan['loan_type'] ?? 'annuity') === 'fixed_principal') {
            return (int) round($principal * $repay / 12); // monatliche Tilgung
        }
        return (int) round($principal * ($rate + $repay) / 12);
    }

    /**
     * @return array<int, array{no:int, date:string, payment:int, interest:int, principal:int, special:int, balance:int, rate:float}>
     *         Beträge in Cent
     */
    public function schedule(): array
    {
        if ($this->schedule !== null) {
            return $this->schedule;
        }
        $balance = Money::toCents($this->loan['principal']);
        $rate = (float) $this->loan['interest_rate'];
        $payment = self::initialPayment($this->loan);
        $fixedPrincipal = ($this->loan['loan_type'] ?? 'annuity') === 'fixed_principal';

        $specials = $this->specials;
        usort($specials, fn ($a, $b) => $a['payment_date'] <=> $b['payment_date']);
        $changes = $this->changes;
        usort($changes, fn ($a, $b) => $a['valid_from'] <=> $b['valid_from']);

        $first = new DateTimeImmutable($this->loan['first_payment_date']);
        $day = (int) $first->format('d');
        $prevDate = $this->loan['payout_date'];
        $rows = [];
        $si = 0;
        $ci = 0;

        for ($n = 0; $n < self::MAX_PERIODS && $balance > 0; $n++) {
            $date = self::addMonths($first, $n, $day);

            while ($ci < count($changes) && $changes[$ci]['valid_from'] <= $date) {
                if ($changes[$ci]['interest_rate'] !== null && $changes[$ci]['interest_rate'] !== '') {
                    $rate = (float) $changes[$ci]['interest_rate'];
                }
                if ($changes[$ci]['monthly_payment'] !== null && $changes[$ci]['monthly_payment'] !== '') {
                    $payment = Money::toCents($changes[$ci]['monthly_payment']);
                }
                $ci++;
            }

            $days = $n === 0 ? self::days360($prevDate, $date) : 30;
            $interest = (int) round($balance * $rate / 100 * $days / 360);

            if ($fixedPrincipal) {
                $principalPart = min($payment, $balance);
                $pay = $principalPart + $interest;
            } else {
                $pay = $payment;
                $principalPart = $pay - $interest;
                if ($principalPart >= $balance) {
                    $principalPart = $balance;
                    $pay = $balance + $interest;
                }
            }
            $balance -= $principalPart;

            $special = 0;
            while ($si < count($specials) && $specials[$si]['payment_date'] <= $date) {
                $amt = min(Money::toCents($specials[$si]['amount']), $balance);
                $special += $amt;
                $balance -= $amt;
                $si++;
            }

            $rows[] = [
                'no' => $n + 1, 'date' => $date, 'payment' => $pay, 'interest' => $interest,
                'principal' => $principalPart, 'special' => $special, 'balance' => $balance, 'rate' => $rate,
            ];
            $prevDate = $date;

            if (!$fixedPrincipal && $principalPart <= 0 && $special === 0) {
                break; // Rate deckt die Zinsen nicht – Kredit würde nie getilgt
            }
        }
        return $this->schedule = $rows;
    }

    /** Restschuld in Euro zum Stichtag (nach allen Raten/Sondertilgungen bis einschließlich Stichtag) */
    public function balanceAt(string $date): float
    {
        return $this->balanceCentsAt($date) / 100;
    }

    public function balanceCentsAt(string $date): int
    {
        if ($date < $this->loan['payout_date']) {
            return 0;
        }
        $balance = Money::toCents($this->loan['principal']);
        $prev = '0000-00-00';
        foreach ($this->schedule() as $row) {
            if ($row['date'] > $date) {
                // Sondertilgungen zwischen letzter Rate und Stichtag
                foreach ($this->specials as $s) {
                    if ($s['payment_date'] <= $date && $s['payment_date'] > $prev) {
                        $balance -= Money::toCents($s['amount']);
                    }
                }
                return max(0, $balance);
            }
            $balance = $row['balance'];
            $prev = $row['date'];
        }
        return max(0, $balance);
    }

    /**
     * Kennzahlen bis zum Stichtag und für die Gesamtlaufzeit (Cent).
     * @return array{balance:int, paid_interest:int, paid_principal:int, paid_special:int, payments:int, end_date:?string,
     *               total_interest:int, total_paid:int, balance_fixed_until:?int, next_payment:?array, paid_off:bool, current_payment:int}
     */
    public function summary(?string $asOf = null): array
    {
        $asOf ??= date('Y-m-d');
        $s = $this->schedule();
        $out = ['balance' => $this->balanceCentsAt($asOf), 'paid_interest' => 0, 'paid_principal' => 0, 'paid_special' => 0, 'payments' => 0,
            'total_interest' => 0, 'total_paid' => 0, 'next_payment' => null, 'current_payment' => self::initialPayment($this->loan)];
        foreach ($s as $row) {
            $out['total_interest'] += $row['interest'];
            $out['total_paid'] += $row['payment'] + $row['special'];
            if ($row['date'] <= $asOf) {
                $out['paid_interest'] += $row['interest'];
                $out['paid_principal'] += $row['principal'];
                $out['paid_special'] += $row['special'];
                $out['payments']++;
                $out['current_payment'] = $row['payment'];
            } elseif ($out['next_payment'] === null) {
                $out['next_payment'] = $row;
                $out['current_payment'] = $row['payment'];
            }
        }
        $last = end($s) ?: null;
        $out['paid_off'] = $last !== null && $last['balance'] === 0;
        $out['end_date'] = $out['paid_off'] ? $last['date'] : null;
        $out['balance_fixed_until'] = !empty($this->loan['fixed_until']) ? $this->balanceCentsAt($this->loan['fixed_until']) : null;
        return $out;
    }

    /** Jahresübersicht: je Jahr Zinsen, Tilgung, Sondertilgung, Restschuld am Jahresende */
    public function yearly(): array
    {
        $out = [];
        foreach ($this->schedule() as $row) {
            $y = substr($row['date'], 0, 4);
            $out[$y] ??= ['year' => $y, 'interest' => 0, 'principal' => 0, 'special' => 0, 'payment' => 0, 'balance' => 0];
            $out[$y]['interest'] += $row['interest'];
            $out[$y]['principal'] += $row['principal'];
            $out[$y]['special'] += $row['special'];
            $out[$y]['payment'] += $row['payment'];
            $out[$y]['balance'] = $row['balance'];
        }
        return array_values($out);
    }

    /** Tage nach 30/360 (deutsche Methode) */
    public static function days360(string $from, string $to): int
    {
        [$y1, $m1, $d1] = array_map('intval', explode('-', $from));
        [$y2, $m2, $d2] = array_map('intval', explode('-', $to));
        $d1 = min($d1, 30);
        $d2 = min($d2, 30);
        return max(0, ($y2 - $y1) * 360 + ($m2 - $m1) * 30 + ($d2 - $d1));
    }

    private static function addMonths(DateTimeImmutable $start, int $n, int $day): string
    {
        $y = (int) $start->format('Y');
        $m = (int) $start->format('n') + $n;
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        return RecurrenceService::dateFor($y, $m, $day);
    }
}
