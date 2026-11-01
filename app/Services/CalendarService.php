<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\CalendarRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * Kalender: Wiederholungen, Vor-/Nachlauf, Vorlagen, Rollen (reine statische Funktionen, testbar) sowie der
 * gemeinsame DB-Teil für Web-Oberfläche und MCP (Termine speichern, Termine eines Benutzers, Budget buchen).
 *
 * Zeiten: start_at/end_at als 'Y-m-d H:i:s', end_at exklusiv (ganztägig: Folgetag 00:00).
 * Vorlauf (Anreise) liegt vor start_at, Nachlauf (Abreise) nach end_at – beides gehört zum Termin.
 */
final class CalendarService
{
    public const RECURRENCE = ['none' => 'keine', 'daily' => 'täglich', 'weekly' => 'wöchentlich', 'monthly' => 'monatlich', 'yearly' => 'jährlich'];

    /** Obergrenze für Vor-/Nachlauf und Dauer (30 Tage) */
    public const MAX_MINUTES = 43200;

    // ---- reine Funktionen -------------------------------------------------------------------

    /**
     * Startdaten ('Y-m-d') aller Vorkommen eines Termins mit Start im Zeitraum [from, to] (inklusive).
     * Monatlich/jährlich: gleicher Tag wie der erste Termin, am Monatsende angepasst (31. → 30./28.).
     * @param array{start_at:string, recurrence?:string, recurrence_interval?:int|string, recurrence_until?:?string} $event
     * @param string[] $exceptions gelöschte Vorkommen ('Y-m-d')
     * @return string[]
     */
    public static function occurrences(array $event, string $from, string $to, array $exceptions = []): array
    {
        $first = substr($event['start_at'], 0, 10);
        $rule = $event['recurrence'] ?? 'none';
        $n = max(1, (int) ($event['recurrence_interval'] ?? 1));
        $until = $event['recurrence_until'] ?? null;
        $limit = ($rule !== 'none' && $until && $until < $to) ? $until : $to;
        $skip = array_flip($exceptions);

        if ($rule === 'none' || !isset(self::RECURRENCE[$rule])) {
            return ($first >= $from && $first <= $to && !isset($skip[$first])) ? [$first] : [];
        }
        if ($limit < $from || $limit < $first) {
            return [];
        }

        $start = new DateTimeImmutable($first);
        [$y, $m, $d] = array_map('intval', explode('-', $first));
        // Zum ersten relevanten Vorkommen springen, statt ab Beginn alles zu durchlaufen
        $k = 0;
        if ($from > $first) {
            $days = (int) $start->diff(new DateTimeImmutable($from))->days;
            $k = match ($rule) {
                'daily'   => intdiv($days, $n),
                'weekly'  => intdiv($days, 7 * $n),
                'monthly' => max(0, intdiv(self::monthsBetween($first, $from), $n) - 1),
                'yearly'  => max(0, intdiv((int) substr($from, 0, 4) - $y, $n) - 1),
            };
        }

        $dates = [];
        for ($guard = 0; $guard < 3000; $guard++, $k++) {
            $date = match ($rule) {
                'daily'   => $start->modify('+' . ($k * $n) . ' days')->format('Y-m-d'),
                'weekly'  => $start->modify('+' . ($k * $n * 7) . ' days')->format('Y-m-d'),
                'monthly' => self::monthDate($y, $m + $k * $n, $d),
                'yearly'  => RecurrenceService::dateFor($y + $k * $n, $m, $d),
            };
            if ($date > $limit) {
                break;
            }
            if ($date >= $from && !isset($skip[$date])) {
                $dates[] = $date;
            }
        }
        return $dates;
    }

    /**
     * Ein Vorkommen eines Termins am Startdatum $date: Zeiten inkl. Vor- und Nachlauf.
     * @return array{date:string, start:string, end:string, lead_start:string, trail_end:string}
     */
    public static function instance(array $event, string $date): array
    {
        $origStart = new DateTimeImmutable($event['start_at']);
        $duration = (new DateTimeImmutable($event['end_at']))->getTimestamp() - $origStart->getTimestamp();
        $start = new DateTimeImmutable($date . ' ' . $origStart->format('H:i:s'));
        $end = $start->modify('+' . max(0, $duration) . ' seconds');
        return [
            'date'       => $date,
            'start'      => $start->format('Y-m-d H:i:s'),
            'end'        => $end->format('Y-m-d H:i:s'),
            'lead_start' => $start->modify('-' . (int) ($event['lead_minutes'] ?? 0) . ' minutes')->format('Y-m-d H:i:s'),
            'trail_end'  => $end->modify('+' . (int) ($event['trail_minutes'] ?? 0) . ' minutes')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Alle Vorkommen, die den Zeitraum [from, to] (Tage, inklusive) berühren – inkl. Vor-/Nachlauf.
     * @return array<int, array{date:string, start:string, end:string, lead_start:string, trail_end:string}>
     */
    public static function instancesTouching(array $event, string $from, string $to, array $exceptions = []): array
    {
        $span = (int) ceil(((new DateTimeImmutable($event['end_at']))->getTimestamp() - (new DateTimeImmutable($event['start_at']))->getTimestamp()) / 86400);
        $before = $span + (int) ceil((int) ($event['trail_minutes'] ?? 0) / 1440) + 1;
        $after = (int) ceil((int) ($event['lead_minutes'] ?? 0) / 1440) + 1;
        $wideFrom = (new DateTimeImmutable($from))->modify("-$before days")->format('Y-m-d');
        $wideTo = (new DateTimeImmutable($to))->modify("+$after days")->format('Y-m-d');
        $out = [];
        foreach (self::occurrences($event, $wideFrom, $wideTo, $exceptions) as $date) {
            $i = self::instance($event, $date);
            if ($i['lead_start'] < $to . ' 24' && $i['trail_end'] > $from . ' 00:00:00') {
                $out[] = $i;
            }
        }
        return $out;
    }

    /**
     * Zerlegt ein Vorkommen tageweise in Abschnitte: 'lead' (Vorlauf/Anreise), 'main' (Termin), 'trail' (Nachlauf/Abreise).
     * Zeiten als 'H:i', Tagesende als '24:00'. Ganztägige Hauptabschnitte haben 'all_day' = true.
     * @return array<string, array<int, array{kind:string, from:string, to:string, all_day:bool}>> Datum => Abschnitte
     */
    public static function segments(array $instance, bool $allDay = false): array
    {
        $parts = [
            ['lead', $instance['lead_start'], $instance['start']],
            ['main', $instance['start'], $instance['end']],
            ['trail', $instance['end'], $instance['trail_end']],
        ];
        $out = [];
        foreach ($parts as [$kind, $a, $b]) {
            if ($b <= $a) {
                continue;
            }
            $day = substr($a, 0, 10);
            for ($guard = 0; $guard < 400; $guard++) {
                $dayStart = $day . ' 00:00:00';
                $next = (new DateTimeImmutable($day))->modify('+1 day')->format('Y-m-d');
                $segFrom = max($a, $dayStart);
                $segTo = min($b, $next . ' 00:00:00');
                if ($segTo > $segFrom) {
                    $out[$day][] = [
                        'kind'    => $kind,
                        'from'    => substr($segFrom, 11, 5),
                        'to'      => $segTo === $next . ' 00:00:00' ? '24:00' : substr($segTo, 11, 5),
                        'all_day' => $allDay && $kind === 'main',
                    ];
                }
                if ($b <= $next . ' 00:00:00') {
                    break;
                }
                $day = $next;
            }
        }
        return $out;
    }

    /** 'H:i' (auch '24:00') in Minuten seit Mitternacht */
    public static function minutes(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm) + [0, 0]);
        return $h * 60 + $m;
    }

    /**
     * Spalten für gleichzeitige Termine (Zeitraster): Intervalle, die sich überschneiden, bilden eine Gruppe und
     * stehen nebeneinander. Jeder Eintrag bekommt die erste freie Spalte; alle in der Gruppe die gleiche Spaltenzahl.
     * Berührt ein Termin den nächsten nur (Ende = Beginn), gilt das nicht als Überschneidung.
     * @param array<int|string, array{0:int, 1:int}> $intervals Schlüssel => [Beginn, Ende] in Minuten
     * @return array<int|string, array{col:int, cols:int}>
     */
    public static function columns(array $intervals): array
    {
        uasort($intervals, fn ($a, $b) => [$a[0], $b[1]] <=> [$b[0], $a[1]]);
        $out = [];
        $group = [];      // Schlüssel der laufenden Gruppe
        $colEnds = [];    // Ende je Spalte in der laufenden Gruppe
        $groupEnd = -1;
        $close = function () use (&$out, &$group, &$colEnds) {
            foreach ($group as $k) {
                $out[$k]['cols'] = count($colEnds);
            }
            $group = [];
            $colEnds = [];
        };
        foreach ($intervals as $key => [$start, $end]) {
            $end = max($end, $start + 1);
            if ($start >= $groupEnd) {
                $close();
            }
            $col = null;
            foreach ($colEnds as $i => $colEnd) {
                if ($colEnd <= $start) {
                    $col = $i;
                    break;
                }
            }
            $col ??= count($colEnds);
            $colEnds[$col] = $end;
            $out[$key] = ['col' => $col, 'cols' => 0];
            $group[] = $key;
            $groupEnd = max($groupEnd, $end);
        }
        $close();
        return $out;
    }

    /**
     * Abschnitte eines Tages fürs Zeitraster: ganztägige getrennt, die übrigen mit Minuten und Spalte. Vor-/Nachlauf
     * gehören zum Termin und teilen sich seine Spalte (man ist in der Zeit ja unterwegs).
     * @param array<int, array{kind:string, from:string, to:string, all_day:bool, idx:int}> $segs
     * @return array{all_day: array, timed: array}
     */
    public static function dayLayout(array $segs): array
    {
        $allDay = [];
        $timed = [];
        $spans = [];
        foreach ($segs as $s) {
            if ($s['all_day']) {
                $allDay[] = $s;
                continue;
            }
            $s['start'] = self::minutes($s['from']);
            $s['end'] = self::minutes($s['to']);
            $timed[] = $s;
            $i = $s['idx'];
            $spans[$i] = isset($spans[$i]) ? [min($spans[$i][0], $s['start']), max($spans[$i][1], $s['end'])] : [$s['start'], $s['end']];
        }
        $cols = self::columns($spans);
        foreach ($timed as &$s) {
            $s += $cols[$s['idx']];
        }
        unset($s);
        usort($timed, fn ($a, $b) => [$a['start'], $a['col']] <=> [$b['start'], $b['col']]);
        return ['all_day' => $allDay, 'timed' => $timed];
    }

    /**
     * Dauer aus Benutzereingabe in Minuten: „90“, „1:30“, „2 h“, „1,5 Std“, „45 min“, „1 Tag“, „2 Tage 3 h“.
     * Leer = 0, ungültig = null.
     */
    public static function parseDuration(string $input): ?int
    {
        $s = mb_strtolower(trim($input));
        if ($s === '') {
            return 0;
        }
        if (preg_match('/^\d+$/', $s)) {
            return min(self::MAX_MINUTES, (int) $s);
        }
        if (preg_match('/^(\d+):([0-5]\d)$/', $s, $m)) {
            return min(self::MAX_MINUTES, (int) $m[1] * 60 + (int) $m[2]);
        }
        $units = ['d' => 1440, 'tag' => 1440, 'tage' => 1440, 'h' => 60, 'std' => 60, 'stunde' => 60, 'stunden' => 60,
                  'm' => 1, 'min' => 1, 'minute' => 1, 'minuten' => 1];
        if (!preg_match_all('/(\d+(?:[.,]\d+)?)\s*([a-zäöü]+)\.?/u', $s, $all, PREG_SET_ORDER)) {
            return null;
        }
        $rest = $s;
        $total = 0.0;
        foreach ($all as $m) {
            $unit = $units[$m[2]] ?? null;
            if ($unit === null) {
                return null;
            }
            $total += (float) str_replace(',', '.', $m[1]) * $unit;
            $rest = str_replace($m[0], '', $rest);
        }
        if (trim($rest) !== '') {
            return null;
        }
        return min(self::MAX_MINUTES, (int) round($total));
    }

    /** Minuten lesbar: „45 Min.“, „1 Std. 30 Min.“, „2 Tage“ */
    public static function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '';
        }
        $d = intdiv($minutes, 1440);
        $h = intdiv($minutes % 1440, 60);
        $m = $minutes % 60;
        $parts = [];
        if ($d) {
            $parts[] = $d . ($d === 1 ? ' Tag' : ' Tage');
        }
        if ($h) {
            $parts[] = $h . ' Std.';
        }
        if ($m) {
            $parts[] = $m . ' Min.';
        }
        return implode(' ', $parts);
    }

    /**
     * Vorlage auf einen Starttermin anwenden: Ende aus der Dauer, Vor-/Nachlauf, Titel, Ort, Farbe.
     * @param string $start 'Y-m-d H:i' bzw. 'Y-m-d' (ganztägig)
     */
    public static function applyTemplate(array $tpl, string $start): array
    {
        $allDay = (bool) ($tpl['all_day'] ?? false);
        $s = new DateTimeImmutable($allDay ? substr($start, 0, 10) : $start);
        $duration = max($allDay ? 1440 : 0, (int) ($tpl['duration_minutes'] ?? 60));
        if ($allDay) {
            $duration = (int) ceil($duration / 1440) * 1440;
        }
        return [
            'title'         => (string) (($tpl['title'] ?? '') ?: ($tpl['name'] ?? '')),
            'location'      => $tpl['location'] ?? null,
            'all_day'       => $allDay ? 1 : 0,
            'start_at'      => $s->format('Y-m-d H:i:s'),
            'end_at'        => $s->modify("+$duration minutes")->format('Y-m-d H:i:s'),
            'lead_minutes'  => (int) ($tpl['lead_minutes'] ?? 0),
            'trail_minutes' => (int) ($tpl['trail_minutes'] ?? 0),
            'color'         => $tpl['color'] ?? '#0d6efd',
            'template_id'   => isset($tpl['id']) ? (int) $tpl['id'] : null,
        ];
    }

    /**
     * Eingaben zu Spalten von calendar_events prüfen (gemeinsam für Web und MCP).
     * Erwartet: title, start ('Y-m-d H:i' oder bei all_day 'Y-m-d'), end (dito; ganztägig = letzter Tag inklusive),
     * all_day, location, note, lead_minutes, trail_minutes, color, recurrence, recurrence_interval, recurrence_until, template_id.
     * @throws \InvalidArgumentException mit deutscher Meldung
     */
    public static function buildEvent(array $in): array
    {
        $title = trim(mb_substr((string) ($in['title'] ?? ''), 0, 190));
        if ($title === '') {
            throw new \InvalidArgumentException('Bitte einen Titel angeben.');
        }
        $allDay = !empty($in['all_day']);
        $start = self::parseDateTime((string) ($in['start'] ?? ''), $allDay);
        if ($start === null) {
            throw new \InvalidArgumentException('Bitte einen gültigen Beginn angeben (Datum' . ($allDay ? '' : ' und Uhrzeit') . ').');
        }
        $endRaw = trim((string) ($in['end'] ?? ''));
        $end = $endRaw === '' ? null : self::parseDateTime($endRaw, $allDay);
        if ($endRaw !== '' && $end === null) {
            throw new \InvalidArgumentException('Das Ende ist ungültig.');
        }
        if ($allDay) {
            $end = ($end ?? $start)->modify('+1 day'); // exklusiv: Folgetag 00:00
        } else {
            $end ??= $start->modify('+60 minutes');
        }
        if ($end <= $start) {
            throw new \InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
        }
        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_MINUTES * 60 * 12) {
            throw new \InvalidArgumentException('Ein Termin darf höchstens ein Jahr dauern.');
        }
        $lead = (int) ($in['lead_minutes'] ?? 0);
        $trail = (int) ($in['trail_minutes'] ?? 0);
        if ($lead < 0 || $trail < 0 || $lead > self::MAX_MINUTES || $trail > self::MAX_MINUTES) {
            throw new \InvalidArgumentException('Vor- und Nachlauf dürfen höchstens 30 Tage betragen.');
        }
        $rule = (string) ($in['recurrence'] ?? 'none');
        if (!isset(self::RECURRENCE[$rule])) {
            throw new \InvalidArgumentException('Unbekannte Wiederholung – erlaubt: ' . implode(', ', array_keys(self::RECURRENCE)) . '.');
        }
        $until = trim((string) ($in['recurrence_until'] ?? ''));
        if ($rule === 'none' || $until === '') {
            $until = null;
        } elseif (valid_date($until) === null || $until < $start->format('Y-m-d')) {
            throw new \InvalidArgumentException('„Wiederholen bis“ muss ein Datum nach dem Beginn sein.');
        }
        $color = (string) ($in['color'] ?? '');
        return [
            'title'               => $title,
            'location'            => trim(mb_substr((string) ($in['location'] ?? ''), 0, 190)) ?: null,
            'note'                => trim(mb_substr((string) ($in['note'] ?? ''), 0, 5000)) ?: null,
            'start_at'            => $start->format('Y-m-d H:i:s'),
            'end_at'              => $end->format('Y-m-d H:i:s'),
            'all_day'             => $allDay ? 1 : 0,
            'lead_minutes'        => $lead,
            'trail_minutes'       => $trail,
            'color'               => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : '#0d6efd',
            'template_id'         => !empty($in['template_id']) ? (int) $in['template_id'] : null,
            'recurrence'          => $rule,
            'recurrence_interval' => $rule === 'none' ? 1 : max(1, min(99, (int) ($in['recurrence_interval'] ?? 1))),
            'recurrence_until'    => $until,
        ];
    }

    /** 'Y-m-d H:i', 'Y-m-dTH:i' oder 'Y-m-d' (ganztägig bzw. 00:00) */
    public static function parseDateTime(string $s, bool $dateOnly = false): ?DateTimeImmutable
    {
        $s = trim(str_replace('T', ' ', $s));
        if (!preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{1,2}):(\d{2})(?::\d{2})?)?$/', $s, $m) || valid_date($m[1]) === null) {
            return null;
        }
        if ($dateOnly) {
            return new DateTimeImmutable($m[1]);
        }
        if (!isset($m[2]) || (int) $m[2] > 23) {
            return null;
        }
        return new DateTimeImmutable(sprintf('%s %02d:%s:00', $m[1], (int) $m[2], $m[3]));
    }

    /** Lesbare Schriftfarbe auf einer Hintergrundfarbe (#rrggbb) */
    public static function textColor(string $hex): string
    {
        if (!preg_match('/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i', $hex, $m)) {
            return '#fff';
        }
        $l = (0.299 * hexdec($m[1]) + 0.587 * hexdec($m[2]) + 0.114 * hexdec($m[3])) / 255;
        return $l > 0.6 ? '#212529' : '#fff';
    }

    /** Eigener Termin oder nur Teilnehmer */
    public static function role(array $event, int $userId): string
    {
        return (int) ($event['created_by'] ?? 0) === $userId ? 'owner' : 'participant';
    }

    /** Bearbeiten/Löschen: nur Ersteller oder Admin */
    public static function canEdit(array $event, int $userId, bool $isAdmin): bool
    {
        return $isAdmin || self::role($event, $userId) === 'owner';
    }

    /**
     * Termine fürs Budget in der Prognose: offene (nicht gebuchte) Vorkommen; überfällige landen auf morgen.
     * @param string[] $dates Vorkommen ('Y-m-d')
     * @param string[] $booked bereits gebuchte Vorkommen
     * @return array<int, array{date:string, occurrence:string, overdue:bool}>
     */
    public static function openBudgetDates(array $dates, array $booked, string $today): array
    {
        $tomorrow = (new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
        $done = array_flip($booked);
        $out = [];
        foreach ($dates as $d) {
            if (isset($done[$d])) {
                continue;
            }
            $out[] = ['date' => max($d, $tomorrow), 'occurrence' => $d, 'overdue' => $d <= $today];
        }
        return $out;
    }

    private static function monthsBetween(string $a, string $b): int
    {
        return ((int) substr($b, 0, 4) - (int) substr($a, 0, 4)) * 12 + ((int) substr($b, 5, 2) - (int) substr($a, 5, 2));
    }

    private static function monthDate(int $y, int $m, int $d): string
    {
        $y += intdiv($m - 1, 12);
        $m = ($m - 1) % 12 + 1;
        return RecurrenceService::dateFor($y, $m, $d);
    }

    // ---- DB-Teil (Web und MCP) ---------------------------------------------------------------

    /**
     * Vorkommen aller Termine, an denen $userId teilnimmt, im Zeitraum [from, to] – sortiert nach Beginn.
     * Je Eintrag: Termin (event), Vorkommen (Zeiten), Rolle, Teilnehmer, eigenes Budget samt Buchungsstatus.
     * $budgetUserId: wessen Budget angezeigt wird (null = keins).
     */
    public static function forUser(int $hid, int $userId, string $from, string $to, ?int $budgetUserId = null): array
    {
        $repo = new CalendarRepository();
        $events = $repo->eventsForUser($hid, $userId, $from, $to);
        if (!$events) {
            return [];
        }
        $ids = array_map(fn ($e) => (int) $e['id'], $events);
        $exceptions = $repo->exceptionsFor($ids);
        $participants = $repo->participantsFor($ids);
        $budgets = $budgetUserId ? $repo->budgetsFor($ids, $budgetUserId) : [];
        $bookings = $budgets ? $repo->bookingsFor(array_map(fn ($b) => (int) $b['id'], $budgets)) : [];

        $out = [];
        foreach ($events as $e) {
            $id = (int) $e['id'];
            $budget = $budgets[$id] ?? null;
            foreach (self::instancesTouching($e, $from, $to, $exceptions[$id] ?? []) as $i) {
                $booking = $budget ? ($bookings[(int) $budget['id']][$i['date']] ?? null) : null;
                $out[] = $i + [
                    'event'        => $e,
                    'role'         => self::role($e, $userId),
                    'participants' => $participants[$id] ?? [],
                    'budget'       => $budget,
                    'booking'      => $booking,
                ];
            }
        }
        usort($out, fn ($a, $b) => [$a['lead_start'], $a['start']] <=> [$b['lead_start'], $b['start']]);
        return $out;
    }

    /**
     * Termin anlegen/ändern inkl. Teilnehmer (Ersteller immer dabei).
     * @param array $data Spalten von calendar_events (geprüft)
     * @param int[] $participantIds
     */
    public static function save(int $hid, int $userId, ?int $eventId, array $data, array $participantIds): int
    {
        $repo = new CalendarRepository();
        return $repo->transaction(function () use ($repo, $hid, $userId, $eventId, $data, $participantIds) {
            if ($eventId) {
                $event = $repo->find($eventId, $hid) ?? throw new RuntimeException('Termin nicht gefunden.');
                $repo->update($eventId, $hid, $data);
                $owner = (int) ($event['created_by'] ?? $userId);
            } else {
                $eventId = $repo->create($hid, $data + ['created_by' => $userId]);
                $owner = $userId;
            }
            $valid = $repo->householdUserIds($hid);
            $ids = array_values(array_unique(array_filter(
                array_map('intval', [...$participantIds, $owner]),
                fn ($id) => in_array($id, $valid, true)
            )));
            $repo->setParticipants($eventId, $ids);
            // Budgets von entfernten Teilnehmern bleiben nur, wenn schon gebucht wurde
            $repo->deleteOrphanBudgets($eventId, $ids);
            return $eventId;
        });
    }

    /**
     * Neue Lage eines Vorkommens (Ziehen im Zeitraster) in Spalten für den Termin umrechnen.
     * Bei Serien wird die ganze Serie um denselben Abstand verschoben (Beginn, ggf. Wiederholungsende).
     * @param string $start/$end neue Zeiten des Vorkommens 'Y-m-d H:i'
     * @return array{data: array, days: int} Spalten für calendar_events, Verschiebung in Tagen
     * @throws InvalidArgumentException
     */
    public static function moveChanges(array $event, string $occurrence, string $start, string $end, int $lead, int $trail): array
    {
        $parse = function (string $v): DateTimeImmutable {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $v);
            if (!$d || $d->format('Y-m-d H:i') !== $v) {
                throw new InvalidArgumentException('Ungültige Zeitangabe.');
            }
            return $d;
        };
        $newStart = $parse($start);
        $newEnd = $parse($end);
        $duration = $newEnd->getTimestamp() - $newStart->getTimestamp();
        if ($event['all_day']) {
            throw new InvalidArgumentException('Ganztägige Termine lassen sich hier nicht verschieben.');
        }
        if ($duration <= 0) {
            throw new InvalidArgumentException('Das Ende muss nach dem Beginn liegen.');
        }
        if ($duration > self::MAX_MINUTES * 60 * 12 || $lead < 0 || $trail < 0 || $lead > self::MAX_MINUTES || $trail > self::MAX_MINUTES) {
            throw new InvalidArgumentException('Dauer, Vor- oder Nachlauf ist zu lang.');
        }
        $old = self::instance($event, $occurrence);
        $delta = $newStart->getTimestamp() - (new DateTimeImmutable($old['start']))->getTimestamp();
        $days = (int) round(((new DateTimeImmutable($newStart->format('Y-m-d')))->getTimestamp() - (new DateTimeImmutable($occurrence))->getTimestamp()) / 86400);
        $seriesStart = (new DateTimeImmutable($event['start_at']))->modify(($delta >= 0 ? '+' : '') . $delta . ' seconds');
        $data = [
            'start_at'      => $seriesStart->format('Y-m-d H:i:s'),
            'end_at'        => $seriesStart->modify('+' . $duration . ' seconds')->format('Y-m-d H:i:s'),
            'lead_minutes'  => $lead,
            'trail_minutes' => $trail,
        ];
        if (($event['recurrence'] ?? 'none') !== 'none' && !empty($event['recurrence_until']) && $days !== 0) {
            $data['recurrence_until'] = (new DateTimeImmutable($event['recurrence_until']))->modify(($days > 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
        }
        return ['data' => $data, 'days' => $days];
    }

    /**
     * Vorkommen verschieben/in der Länge ändern. $onlyThis bei Serien: das Vorkommen wird als eigener Termin
     * abgelöst (Ausnahme in der Serie, Teilnehmer und Budgets kopiert, gebuchte Budgets wandern mit).
     * @return int ID des geänderten bzw. neuen Termins
     */
    public static function move(int $hid, array $event, string $occurrence, string $start, string $end, int $lead, int $trail, bool $onlyThis): int
    {
        $change = self::moveChanges($event, $occurrence, $start, $end, $lead, $trail);
        $newDate = substr($start, 0, 10);
        $repo = new CalendarRepository();
        $id = (int) $event['id'];
        return $repo->transaction(function () use ($repo, $hid, $event, $id, $occurrence, $start, $end, $lead, $trail, $onlyThis, $change, $newDate) {
            if ($event['recurrence'] === 'none') {
                $repo->update($id, $hid, $change['data']);
                $repo->moveBookings($id, $occurrence, $newDate);
                return $id;
            }
            if (!$onlyThis) {
                $repo->update($id, $hid, $change['data']);
                $repo->shiftOccurrenceDates($id, $change['days']);
                return $id;
            }
            $repo->addException($id, $occurrence);
            $copy = array_intersect_key($event, array_flip(CalendarRepository::EVENT_FIELDS));
            $newId = $repo->create($hid, [
                'start_at' => $start . ':00', 'end_at' => $end . ':00', 'lead_minutes' => $lead, 'trail_minutes' => $trail,
                'recurrence' => 'none', 'recurrence_interval' => 1, 'recurrence_until' => null, 'created_by' => $event['created_by'],
            ] + $copy);
            $repo->setParticipants($newId, array_column($repo->participantsFor([$id])[$id] ?? [], 'id'));
            $repo->copyBudgets($id, $newId);
            $repo->moveBookings($id, $occurrence, $newDate, $newId);
            return $newId;
        });
    }

    /**
     * Budget eines Vorkommens als Ausgabe buchen. Prüft Besitz und Buchungsrecht.
     * @return int ID der Buchung
     */
    public static function bookBudget(int $hid, int $userId, array $budget, array $event, string $occurrence, int $cents,
                                      int $accountId, string $date, ?int $categoryId, string $note = ''): int
    {
        if ((int) $budget['user_id'] !== $userId) {
            throw new RuntimeException('Nur das eigene Budget kann gebucht werden.');
        }
        if ($cents <= 0) {
            throw new RuntimeException('Bitte einen positiven Betrag angeben.');
        }
        if (!Auth::can($accountId, 'book')) {
            throw new RuntimeException('Auf dieses Konto darfst du nicht buchen.');
        }
        $repo = new CalendarRepository();
        if ($repo->booking((int) $budget['id'], $occurrence)) {
            throw new RuntimeException('Dieses Budget ist für den Termin schon gebucht.');
        }
        return $repo->transaction(function () use ($repo, $hid, $userId, $budget, $event, $occurrence, $cents, $accountId, $date, $categoryId, $note) {
            $txId = (new TransactionService())->create($hid, [
                'account_id'   => $accountId,
                'booking_date' => $date,
                'amount'       => Money::toDecimal(-$cents),
                'payee'        => mb_substr($event['title'], 0, 190),
                'category_id'  => $categoryId,
                'note'         => mb_substr(trim('Kalender ' . date_de($occurrence) . ($note !== '' ? ' · ' . $note : '')), 0, 255),
                'source'       => 'calendar',
                'created_by'   => $userId,
            ]);
            $repo->addBooking((int) $budget['id'], $occurrence, $txId, $userId);
            return $txId;
        });
    }
}
