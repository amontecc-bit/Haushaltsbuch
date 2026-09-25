<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\RecurringRepository;
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
}

