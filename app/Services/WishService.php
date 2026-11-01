<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;

/**
 * Wunschliste mit virtuellem Sparplan (reine, testbare Funktionen – gebucht wird nichts).
 *
 * Sparplan: Startguthaben `plan_saved` liegt ab `plan_start` bereit, dazu jeden Monat am Tag von `plan_start`
 * (Monatsende geklemmt) die Rate `plan_amount` – die erste Rate am Starttag selbst. Sobald der Preis erreicht ist,
 * enden die Raten. Ein Sparplan wird aus einem Posten (Fixkosten/Dauerauftrag) bezahlt; das Ersparte liegt auf dem
 * Konto der Einnahmeseite dieses Postens.
 */
final class WishService
{
    /** Höchstzahl Raten in Vorschau-Rechnungen (50 Jahre) */
    private const MAX_RATES = 600;

    /** Datum der k-ten Rate (k = 0: erste Rate am Starttag) */
    public static function rateDate(string $start, int $k): string
    {
        $y = (int) substr($start, 0, 4);
        $m = (int) substr($start, 5, 2) - 1 + $k;
        $y += intdiv($m, 12);
        return RecurrenceService::dateFor($y, $m % 12 + 1, (int) substr($start, 8, 2));
    }

    /** Anzahl der Raten mit Datum ≤ $date */
    public static function ratesUntil(string $start, string $date): int
    {
        if ($date < $start) {
            return 0;
        }
        $n = ((int) substr($date, 0, 4) - (int) substr($start, 0, 4)) * 12 + (int) substr($date, 5, 2) - (int) substr($start, 5, 2);
        return self::rateDate($start, $n) <= $date ? $n + 1 : $n;
    }

    /** Wie viele Raten fehlen noch bis zum Preis? null = nie (keine Rate) bzw. kein Preis */
    public static function ratesNeeded(int $saved, int $rate, ?int $price): ?int
    {
        if ($price === null) {
            return null;
        }
        if ($saved >= $price) {
            return 0;
        }
        return $rate > 0 ? (int) ceil(($price - $saved) / $rate) : null;
    }

    /** Hat der Wunsch einen Sparplan (Rate > 0 und Startdatum)? */
    public static function hasPlan(array $w): bool
    {
        return Money::toCents($w['plan_amount'] ?? null) > 0 && !empty($w['plan_start']);
    }

    /**
     * Stand und Vorschau des Sparplans zum Stichtag (Beträge in Cent).
     *
     * @return array{has_plan:bool, rate:int, price:?int, saved:int, remaining:?int, progress:?float, reached:bool,
     *               running:bool, reach_date:?string, next_rate:?string, rates_left:?int, late:bool, rate_for_wish_date:?int}
     */
    public static function plan(array $w, string $today): array
    {
        $has = self::hasPlan($w);
        $rate = $has ? Money::toCents($w['plan_amount']) : 0;
        $price = ($w['price'] ?? null) !== null && $w['price'] !== '' ? Money::toCents($w['price']) : null;
        $start = $has ? (string) $w['plan_start'] : null;
        $base = Money::toCents($w['plan_saved'] ?? 0);

        $needed = self::ratesNeeded($base, $rate, $price);
        $paid = $has ? self::ratesUntil($start, $today) : 0;
        if ($needed !== null) {
            $paid = min($paid, $needed);
        }
        $saved = $base + $paid * $rate;
        if ($price !== null) {
            $saved = min($saved, max($price, $base)); // letzte Rate nur so weit wie nötig
        }
        $reached = $price !== null && $saved >= $price;
        $open = ($w['status'] ?? 'open') === 'open';
        $running = $open && $has && !$reached;

        $reachDate = null;
        $ratesLeft = null;
        if ($price !== null && !$reached && $has && $needed !== null && $needed <= self::MAX_RATES) {
            $reachDate = self::rateDate($start, $needed - 1);
            $ratesLeft = $needed - $paid;
        } elseif ($reached) {
            $ratesLeft = 0;
        }
        $nextRate = $running ? self::rateDate($start, self::ratesUntil($start, $today)) : null;

        // Wunschtermin: reicht der Plan? Sonst nötige Rate bis dahin vorschlagen
        $wishDate = $w['wanted_by'] ?? null;
        $late = false;
        $rateForWish = null;
        if ($open && $wishDate && $price !== null && !$reached) {
            $late = $reachDate === null || $reachDate > $wishDate;
            $from = $nextRate ?? $today;
            $n = self::ratesUntil($from, $wishDate);
            if ($n > 0) {
                $rateForWish = (int) ceil(($price - $saved) / $n);
            }
        }

        return [
            'has_plan'           => $has,
            'rate'               => $rate,
            'price'              => $price,
            'saved'              => $saved,
            'remaining'          => $price !== null ? max(0, $price - $saved) : null,
            'progress'           => $price ? min(100.0, $saved / $price * 100) : null,
            'reached'            => $reached,
            'running'            => $running,
            'reach_date'         => $reachDate,
            'next_rate'          => $nextRate,
            'rates_left'         => $ratesLeft,
            'late'               => $late,
            'rate_for_wish_date' => $rateForWish,
        ];
    }

    /**
     * Kommende Raten mit Zwischenstand (für die Vorschau), höchstens $limit Zeilen.
     * @return array<int, array{date:string, rate:int, saved:int}>
     */
    public static function schedule(array $w, string $today, int $limit = 120): array
    {
        $p = self::plan($w, $today);
        if (!$p['running']) {
            return [];
        }
        $start = (string) $w['plan_start'];
        $k = self::ratesUntil($start, $today);
        $saved = $p['saved'];
        $out = [];
        while (count($out) < $limit) {
            $rate = $p['price'] !== null ? min($p['rate'], $p['price'] - $saved) : $p['rate'];
            if ($rate <= 0) {
                break;
            }
            $saved += $rate;
            $out[] = ['date' => self::rateDate($start, $k++), 'rate' => $rate, 'saved' => $saved];
        }
        return $out;
    }

    /** Monatsbetrag eines Postens (Cent, positiv); Quartal/Halbjahr/Jahr anteilig */
    public static function recurringMonthly(array $rec): int
    {
        return abs((int) round(Money::toCents($rec['amount']) / (RecurrenceService::STEPS[$rec['interval']] ?? 1)));
    }

    /**
     * Konto auf der Einnahmeseite eines Postens: Zielkonto einer Umbuchung, das Konto einer Einnahme,
     * bei einer Ausgabe mit Gegeneintrag dessen Konto. Reine Ausgabe ohne Gegenseite → null (Konto frei wählbar).
     */
    public static function incomeAccountId(array $rec): ?int
    {
        if (!empty($rec['to_account_id'])) {
            return (int) $rec['to_account_id'];
        }
        if (Money::toCents($rec['amount']) > 0) {
            return (int) $rec['account_id'];
        }
        return !empty($rec['counterpart_account_id']) ? (int) $rec['counterpart_account_id'] : null;
    }

    /** Läuft der Posten heute (aktiv, nicht beendet)? */
    public static function recurringRuns(array $rec, string $today): bool
    {
        return (bool) $rec['active'] && (empty($rec['end_date']) || $rec['end_date'] >= $today);
    }

    /**
     * Auslastung eines Postens durch die laufenden Sparpläne.
     * @param int[] $rates Monatsraten (Cent) der laufenden Sparpläne
     * @return array{capacity:int, used:int, free:int, over:bool}
     */
    public static function usage(array $rec, array $rates, string $today): array
    {
        $capacity = self::recurringRuns($rec, $today) ? self::recurringMonthly($rec) : 0;
        $used = array_sum($rates);
        return ['capacity' => $capacity, 'used' => $used, 'free' => $capacity - $used, 'over' => $used > $capacity];
    }

    /**
     * Deckung der Ersparnisse durch den Kontostand: Wünsche in der übergebenen Reihenfolge bedienen
     * (dringendste zuerst), bis der Saldo aufgebraucht ist.
     * @param array<int, int> $saved Wunsch-ID => Erspartes (Cent)
     * @return array<int, array{covered:int, missing:int}>
     */
    public static function allocate(array $saved, int $balance): array
    {
        $left = max(0, $balance);
        $out = [];
        foreach ($saved as $id => $amount) {
            $covered = min($amount, $left);
            $left -= $covered;
            $out[$id] = ['covered' => $covered, 'missing' => $amount - $covered];
        }
        return $out;
    }

    /** Sortierschlüssel: Wunschtermin bzw. Erreichbarkeit zuerst, ohne Termin ans Ende */
    public static function urgency(array $w, array $plan): string
    {
        return ($w['wanted_by'] ?: ($plan['reach_date'] ?? '9999-12-31')) . sprintf('|%010d', (int) $w['id']);
    }
}
