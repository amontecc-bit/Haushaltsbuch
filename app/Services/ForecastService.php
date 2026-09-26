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
 * + variabler Saldo pro Monat, gleichmäßig auf die Tage verteilt: je Konto entweder der Ø der letzten N vollen Monate
 *   (ohne Daueraufträge, Umbuchungen, erkannte Umbuchungspaare und ausgeklammerte Kategorien) oder – in einem
 *   Szenario – ein von Hand festgelegter Wert.
 */
final class ForecastService
{
    public function __construct(private readonly int $householdId, private readonly array $accountIds)
    {
    }

    /**
     * @param array<int,float>|null $manual Szenario: von Hand festgelegter variabler Monatssaldo je Konto (Euro)
     * @return array{accounts:array, dates:string[], series:array<int,float[]>, total:float[], events:array,
     *               variable:array<int,float>, computed:array<int,float>, manual:array<int,float>, baseline:?float[], breakdown:array, min:array}
     */
    public function run(int $months = 12, int $avgMonths = 6, ?string $today = null, ?array $manual = null): array
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
        $variable = $this->variable($ids, $avgMonths, $today);
        $manual = array_intersect_key($manual ?? [], array_flip($ids));
        $effective = self::effectiveVariable($ids, $variable['averages'], $manual);
        $projection = self::project($start, $events, $effective, $today, $end);
        // Vergleichslinie „nur berechneter Ø“, wenn das Szenario etwas ändert
        $baseline = $manual && $effective != self::effectiveVariable($ids, $variable['averages'], [])
            ? self::project($start, $events, $variable['averages'], $today, $end)['total']
            : null;

        return ['accounts' => $accounts, 'events' => $events, 'variable' => $effective, 'computed' => $variable['averages'],
                'manual' => $manual, 'baseline' => $baseline, 'breakdown' => $variable['breakdown']] + $projection;
    }

    /**
     * Variabler Monatssaldo je Konto: Handwert, sonst berechneter Ø, sonst 0.
     * @param int[] $ids
     * @param array<int,float> $computed
     * @param array<int,float> $manual
     * @return array<int,float>
     */
    public static function effectiveVariable(array $ids, array $computed, array $manual): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = round((float) ($manual[$id] ?? $computed[$id] ?? 0), 2);
        }
        return $out;
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
        return $this->variable($ids, $avgMonths, $today)['averages'];
    }

    /**
     * Variabler Ø je Konto plus Aufschlüsselung nach Hauptkategorie und nach nicht gezählten Beträgen.
     * Nicht variabel sind: Fixkosten (recurring_id), Umbuchungen (transfer_group), Buchungen in Kategorien mit
     * „in Prognose ausklammern“ (auch über die Hauptkategorie) und erkannte Umbuchungspaare aus dem Import:
     * Gegenbuchung mit umgekehrtem Betrag auf einem anderen Prognose-Konto, höchstens 3 Tage auseinander,
     * selbst weder Umbuchung noch Fixkosten (sonst würde sie – z. B. zusätzlich zu einer Fixkosten-Umbuchung – doppelt zählen).
     * @return array{averages: array<int,float>, breakdown: array<int, array{categories: array, excluded: array<string,float>}>}
     */
    private function variable(array $ids, int $avgMonths, string $today): array
    {
        if (!$ids || $avgMonths < 1) {
            return ['averages' => [], 'breakdown' => []];
        }
        $firstOfThisMonth = substr($today, 0, 7) . '-01';
        $windowStart = (new DateTimeImmutable($firstOfThisMonth))->modify("-$avgMonths months")->format('Y-m-d');
        $windowEnd = (new DateTimeImmutable($firstOfThisMonth))->modify('-1 day')->format('Y-m-d');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $db = Database::connection();

        $st = $db->prepare("SELECT account_id, MIN(booking_date) AS first_date FROM transactions
                            WHERE household_id = ? AND account_id IN ($in) GROUP BY account_id");
        $st->execute([$this->householdId, ...$ids]);
        $months = [];
        foreach ($st->fetchAll() as $r) {
            $first = max($r['first_date'], $windowStart);
            if ($first > $windowEnd) {
                continue; // noch kein voller Monat Historie
            }
            $months[(int) $r['account_id']] = max(1, min($avgMonths, count(ReportService::monthRange($first, $windowEnd))));
        }

        $st = $db->prepare(
            "SELECT t.account_id, COALESCE(c.parent_id, c.id) AS cid,
                    CASE WHEN t.recurring_id IS NOT NULL THEN 'fixed'
                         WHEN t.transfer_group IS NOT NULL THEN 'transfer'
                         WHEN EXISTS (SELECT 1 FROM transactions o
                                      WHERE o.household_id = t.household_id AND o.account_id IN ($in) AND o.account_id <> t.account_id
                                        AND o.amount = -t.amount AND o.transfer_group IS NULL AND o.recurring_id IS NULL
                                        AND o.booking_date BETWEEN t.booking_date - INTERVAL 3 DAY AND t.booking_date + INTERVAL 3 DAY) THEN 'pair'
                         WHEN c.exclude_from_forecast = 1 OR p.exclude_from_forecast = 1 THEN 'category'
                         ELSE 'variable' END AS kind,
                    SUM(t.amount) AS total
             FROM transactions t
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE t.household_id = ? AND t.account_id IN ($in) AND t.booking_date BETWEEN ? AND ? AND t.amount <> 0
             GROUP BY t.account_id, cid, kind"
        );
        $st->execute([...$ids, $this->householdId, ...$ids, $windowStart, $windowEnd]);

        $cats = [];
        foreach ($db->query('SELECT id, name, color FROM categories WHERE household_id = ' . (int) $this->householdId) as $c) {
            $cats[(int) $c['id']] = $c;
        }
        $averages = [];
        $breakdown = [];
        foreach ($st->fetchAll() as $r) {
            $acc = (int) $r['account_id'];
            if (!isset($months[$acc]) || $r['kind'] === 'transfer') {
                continue;
            }
            $avg = (float) $r['total'] / $months[$acc];
            $breakdown[$acc] ??= ['categories' => [], 'excluded' => []];
            if ($r['kind'] === 'variable') {
                $averages[$acc] = ($averages[$acc] ?? 0) + $avg;
                $cid = (int) $r['cid'];
                $breakdown[$acc]['categories'][$cid] ??= [
                    'id'    => $cid ?: null,
                    'name'  => $cats[$cid]['name'] ?? 'Ohne Kategorie',
                    'color' => $cats[$cid]['color'] ?? '#adb5bd',
                    'avg'   => 0.0,
                ];
                $breakdown[$acc]['categories'][$cid]['avg'] += $avg;
            } else {
                $breakdown[$acc]['excluded'][$r['kind']] = ($breakdown[$acc]['excluded'][$r['kind']] ?? 0) + $avg;
            }
        }
        foreach ($months as $acc => $_) {
            $averages[$acc] = round($averages[$acc] ?? 0, 2);
        }
        foreach ($breakdown as &$b) {
            $b['categories'] = array_values(array_filter($b['categories'], fn ($c) => abs($c['avg']) >= 0.005));
            usort($b['categories'], fn ($x, $y) => abs($y['avg']) <=> abs($x['avg']));
        }
        unset($b);
        return ['averages' => $averages, 'breakdown' => $breakdown];
    }
}
