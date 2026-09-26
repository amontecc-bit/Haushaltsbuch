<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Money;
use App\Repositories\RecurringRepository;
use App\Repositories\TransactionRepository;
use DateTimeImmutable;

final class RecurrenceService
{
    public const STEPS = ['monthly' => 1, 'quarterly' => 3, 'halfyearly' => 6, 'yearly' => 12];

    /**
     * Alle Termine einer Vorlage im Zeitraum [from, to] (inklusive), als 'Y-m-d'.
     * Termine liegen im Abstand des Intervalls ab dem Monat des Startdatums, am gewünschten Tag
     * (an Monatsende angepasst: 31. → 30./28./29.).
     *
     * @param array{interval:string, day_of_month:int|string, start_date:string, end_date:?string} $tpl
     * @return string[]
     */
    public static function occurrences(array $tpl, string $from, string $to): array
    {
        $step = self::STEPS[$tpl['interval']] ?? 1;
        $day = max(1, min(31, (int) $tpl['day_of_month']));
        $start = $tpl['start_date'];
        $end = $tpl['end_date'] ?? null;
        $limit = ($end && $end < $to) ? $end : $to;
        if ($limit < $from || $limit < $start) {
            return [];
        }

        [$y, $m] = array_map('intval', explode('-', substr($start, 0, 7)));
        $lower = max($from, $start);

        // Zum ersten relevanten Zeitraum springen, statt ab Start jeden Monat zu durchlaufen
        [$ly, $lm] = array_map('intval', explode('-', substr($lower, 0, 7)));
        $monthsDiff = ($ly - $y) * 12 + ($lm - $m);
        if ($monthsDiff > $step) {
            $skip = intdiv($monthsDiff, $step) - 1;
            $m += $skip * $step;
            $y += intdiv($m - 1, 12);
            $m = ($m - 1) % 12 + 1;
        }

        $dates = [];
        for ($guard = 0; $guard < 2000; $guard++) {
            $date = self::dateFor($y, $m, $day);
            if ($date > $limit) {
                break;
            }
            if ($date >= $lower) {
                $dates[] = $date;
            }
            $m += $step;
            while ($m > 12) {
                $m -= 12;
                $y++;
            }
        }
        return $dates;
    }

    /** Nächster Termin ab (inkl.) $from */
    public static function nextOccurrence(array $tpl, ?string $from = null): ?string
    {
        $from ??= date('Y-m-d');
        $to = (new DateTimeImmutable($from))->modify('+13 months')->format('Y-m-d');
        return self::occurrences($tpl, $from, $to)[0] ?? null;
    }

    public static function dateFor(int $y, int $m, int $day): string
    {
        $dim = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
        return sprintf('%04d-%02d-%02d', $y, $m, min($day, $dim));
    }

    /** Umrechnung auf einen Monatsbetrag (für Übersichten) */
    public static function monthlyAmount(string $amount, string $interval): float
    {
        return (float) $amount / (self::STEPS[$interval] ?? 1);
    }

    /**
     * Legt alle fälligen Buchungen bis heute an.
     * @return int Anzahl erzeugter Buchungen
     */
    public static function materializeDue(int $householdId, ?string $today = null): int
    {
        $today ??= date('Y-m-d');
        $repo = new RecurringRepository();
        $tx = new TransactionService();
        $count = 0;
        foreach ($repo->activeForBooking($householdId) as $tpl) {
            $from = $tpl['last_booked_date']
                ? (new DateTimeImmutable($tpl['last_booked_date']))->modify('+1 day')->format('Y-m-d')
                : $tpl['start_date'];
            $dates = self::occurrences($tpl, $from, $today);
            if (!$dates) {
                continue;
            }
            $repo->transaction(function () use ($dates, $tpl, $tx, $repo, $householdId, &$count) {
                foreach ($dates as $date) {
                    $tx->createFromRecurring($householdId, $tpl, $date);
                    $count++;
                }
                $repo->markBooked((int) $tpl['id'], end($dates));
            });
        }
        return $count;
    }

    /**
     * Monatliche fixe Einnahmen/Ausgaben (Cent) der aktiven, nicht beendeten Vorlagen.
     * Ohne Kontofilter zählen Umbuchungen nicht (Geld bleibt im Haushalt). Mit Kontofilter zählen Umbuchungen,
     * die die Grenze der gewählten Konten überschreiten: hinaus als Ausgabe, herein als Einnahme.
     *
     * @param int[] $accountFilter leer = alle Konten
     * @return array{income:int, expense:int}
     */
    public static function monthlyTotals(array $rows, array $accountFilter, string $today): array
    {
        $income = $expense = 0;
        foreach ($rows as $r) {
            if (!$r['active'] || ($r['end_date'] && $r['end_date'] < $today)) {
                continue;
            }
            $cents = (int) round(Money::toCents($r['amount']) / (self::STEPS[$r['interval']] ?? 1));
            if ($r['to_account_id']) {
                if (!$accountFilter) {
                    continue;
                }
                $fromIn = in_array((int) $r['account_id'], $accountFilter, true);
                $toIn = in_array((int) $r['to_account_id'], $accountFilter, true);
                if ($fromIn === $toIn) {
                    continue;
                }
                $cents = $fromIn ? -abs($cents) : abs($cents);
            } elseif ($accountFilter && !in_array((int) $r['account_id'], $accountFilter, true)) {
                continue;
            }
            $cents > 0 ? $income += $cents : $expense += $cents;
        }
        return ['income' => $income, 'expense' => $expense];
    }

    /** Liegt $date höchstens $days Tage neben einem Termin der Vorlage? */
    public static function matchesOccurrence(array $tpl, string $date, int $days = 5): bool
    {
        $from = date('Y-m-d', strtotime("$date -$days days"));
        $to = date('Y-m-d', strtotime("$date +$days days"));
        return (bool) self::occurrences($tpl, $from, $to);
    }

    /** Passt der Empfänger zu einem der bekannten Empfänger? Ohne bekannte Empfänger zählt nur Betrag und Datum. */
    public static function samePayeeAny(array $known, ?string $payee): bool
    {
        if (!$known) {
            return true;
        }
        foreach ($known as $k) {
            if (CategorizationService::samePayee($k, $payee) || CategorizationService::samePayee($payee, $k)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verknüpft vorhandene Buchungen ohne Vorlage (z. B. aus CSV-Import) mit passenden Fixkosten:
     * gleiches Konto, gleicher Betrag, Datum nahe einem Termin. Dadurch werden sie als Fixkosten gekennzeichnet
     * und in der Prognose nicht doppelt (als variable Ausgabe) gezählt.
     *
     * @param int|null $templateId nur diese Vorlage, sonst alle aktiven des Haushalts
     * @return int Anzahl verknüpfter Buchungen
     */
    public static function linkExisting(int $householdId, ?int $templateId = null): int
    {
        $repo = new RecurringRepository();
        $txRepo = new TransactionRepository();
        $templates = $templateId
            ? array_filter([$repo->find($templateId, $householdId)])
            : $repo->activeOfHousehold($householdId);
        $count = 0;
        foreach ($templates as $tpl) {
            if ($tpl['to_account_id']) {
                continue;
            }
            $candidates = $txRepo->unlinkedForTemplate($householdId, $tpl);
            if (!$candidates) {
                continue;
            }
            // Nur gleicher Betrag reicht nicht (z. B. 15,99 € bei Netflix und im Laden) – Empfänger muss passen
            $payees = array_filter([$tpl['payee'], ...$txRepo->payeesForRecurring((int) $tpl['id'])]);
            $ids = [];
            foreach ($candidates as $t) {
                if (self::matchesOccurrence($tpl, $t['booking_date']) && self::samePayeeAny($payees, $t['payee'])) {
                    $ids[] = (int) $t['id'];
                }
            }
            $count += $txRepo->linkRecurring($householdId, $ids, (int) $tpl['id']);
        }
        return $count;
    }
}

