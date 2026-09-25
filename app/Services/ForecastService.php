<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\RecurringRepository;
use DateTimeImmutable;

/**
 * Prognose der Kontostände:
 *   Startsaldo heute
 * + künftige Termine aller aktiven wiederkehrenden Buchungen (inkl. Kreditraten, Umbuchungen)
 * + durchschnittlicher variabler Saldo pro Monat aus den letzten N vollen Monaten
 *   (ohne Daueraufträge und Umbuchungen, damit nichts doppelt zählt), gleichmäßig auf die Tage verteilt.
 */
final class ForecastService
{
    public function __construct(private readonly int $householdId, private readonly array $accountIds)
    {
    }

    /**
     * @return array{accounts:array, dates:string[], series:array<int,float[]>, total:float[], events:array, variable:array<int,float>, min:array}
     */
    public function run(int $months = 12, int $avgMonths = 6, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $end = (new DateTimeImmutable($today))->modify("+$months months")->format('Y-m-d');

        $accounts = array_values(array_filter(
            (new AccountRepository())->withBalances($this->householdId, $this->accountIds, false, $today),
            fn ($a) => (int) $a['include_in_forecast'] === 1
        ));
        $ids = array_map(fn ($a) => (int) $a['id'], $accounts);
        $start = [];
        foreach ($accounts as $a) {
            $start[(int) $a['id']] = Money::toCents($a['balance']) / 100;
        }

        $events = $this->futureEvents($ids, $today, $end);
        $variable = $this->variableAverages($ids, $avgMonths, $today);
        $projection = self::project($start, $events, $variable, $today, $end);

        return ['accounts' => $accounts, 'events' => $events, 'variable' => $variable] + $projection;
    }

    /**
     * Reine Berechnung (testbar).
     * @param array<int,float> $start Startsaldo je Konto (Euro)
     * @param array<int, array{date:string, account_id:int, amount:float}> $events
     * @param array<int,float> $monthlyVariable variabler Saldo je Konto und Monat (Euro)
     * @return array{dates:string[], series:array<int,float[]>, total:float[], min:array{date:?string, value:float, negative_from:?string}}
     */
    public static function project(array $start, array $events, array $monthlyVariable, string $from, string $to): array
    {
        $byDate = [];
        foreach ($events as $e) {
            $byDate[$e['date']][(int) $e['account_id']] = ($byDate[$e['date']][(int) $e['account_id']] ?? 0) + $e['amount'];
        }
        $balance = $start;
        $dates = [];
        $series = array_fill_keys(array_keys($start), []);
        $total = [];
        $min = ['date' => $from, 'value' => array_sum($start), 'negative_from' => null];

        $d = new DateTimeImmutable($from);
        $endTs = (new DateTimeImmutable($to))->getTimestamp();
        $first = true;
        while ($d->getTimestamp() <= $endTs) {
            $ds = $d->format('Y-m-d');
            if (!$first) {
                $dim = (int) $d->format('t');
                foreach ($balance as $acc => $_) {
                    $balance[$acc] += ($monthlyVariable[$acc] ?? 0) / $dim;
                }
                foreach ($byDate[$ds] ?? [] as $acc => $amt) {
                    if (array_key_exists($acc, $balance)) {
                        $balance[$acc] += $amt;
                    }
                }
            }
            $first = false;
            $dates[] = $ds;
            $sum = 0;
            foreach ($balance as $acc => $b) {
                $series[$acc][] = round($b, 2);
                $sum += $b;
            }
            $total[] = round($sum, 2);
            if ($sum < $min['value']) {
                $min['value'] = $sum;
                $min['date'] = $ds;
            }
            if ($sum < 0 && $min['negative_from'] === null) {
                $min['negative_from'] = $ds;
            }
            $d = $d->modify('+1 day');
        }
        $min['value'] = round($min['value'], 2);
        return ['dates' => $dates, 'series' => $series, 'total' => $total, 'min' => $min];
    }

    /** Künftige Termine (nach heute) aus allen aktiven Vorlagen */
    private function futureEvents(array $ids, string $today, string $end): array
    {
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $events = [];
        foreach ((new RecurringRepository())->all($this->householdId, $this->accountIds, true) as $tpl) {
            $from = $tomorrow;
            if ($tpl['last_booked_date'] && $tpl['last_booked_date'] >= $from) {
                $from = (new DateTimeImmutable($tpl['last_booked_date']))->modify('+1 day')->format('Y-m-d');
            }
            $amount = (float) $tpl['amount'];
            foreach (RecurrenceService::occurrences($tpl, $from, $end) as $date) {
                $label = $tpl['payee'] ?: ($tpl['category_name'] ?: ($tpl['to_account_name'] ? 'Umbuchung' : 'Dauerauftrag'));
                if ($tpl['to_account_id']) {
                    $abs = abs($amount);
                    if (in_array((int) $tpl['account_id'], $ids, true)) {
                        $events[] = ['date' => $date, 'account_id' => (int) $tpl['account_id'], 'amount' => -$abs, 'label' => $label . ' → ' . $tpl['to_account_name'], 'recurring_id' => (int) $tpl['id']];
                    }
                    if (in_array((int) $tpl['to_account_id'], $ids, true)) {
                        $events[] = ['date' => $date, 'account_id' => (int) $tpl['to_account_id'], 'amount' => $abs, 'label' => $label . ' ← ' . $tpl['account_name'], 'recurring_id' => (int) $tpl['id']];
                    }
                } elseif (in_array((int) $tpl['account_id'], $ids, true)) {
                    $events[] = ['date' => $date, 'account_id' => (int) $tpl['account_id'], 'amount' => $amount, 'label' => $label, 'recurring_id' => (int) $tpl['id']];
                }
            }
        }
        usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);
        return $events;
    }

    /**
     * Durchschnittlicher variabler Monatssaldo je Konto aus den letzten $avgMonths vollen Monaten.
     * Berücksichtigt nur Monate ab der ersten Buchung auf dem Konto.
     * @return array<int,float>
     */
    public function variableAverages(array $ids, int $avgMonths, string $today): array
    {
        if (!$ids || $avgMonths < 1) {
            return [];
        }
        $firstOfThisMonth = substr($today, 0, 7) . '-01';
        $windowStart = (new DateTimeImmutable($firstOfThisMonth))->modify("-$avgMonths months")->format('Y-m-d');
        $windowEnd = (new DateTimeImmutable($firstOfThisMonth))->modify('-1 day')->format('Y-m-d');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = Database::connection()->prepare(
            "SELECT account_id,
                    SUM(CASE WHEN recurring_id IS NULL AND transfer_group IS NULL AND booking_date BETWEEN ? AND ? THEN amount ELSE 0 END) AS var_sum,
                    MIN(booking_date) AS first_date
             FROM transactions WHERE household_id = ? AND account_id IN ($in)
             GROUP BY account_id"
        );
        $st->execute([$windowStart, $windowEnd, $this->householdId, ...$ids]);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $first = max($r['first_date'], $windowStart);
            if ($first > $windowEnd) {
                continue; // noch kein voller Monat Historie
            }
            $monthsAvailable = count(ReportService::monthRange($first, $windowEnd));
            $out[(int) $r['account_id']] = round((float) $r['var_sum'] / max(1, min($avgMonths, $monthsAvailable)), 2);
        }
        return $out;
    }
}
