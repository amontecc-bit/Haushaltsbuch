<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CalendarService;
use App\Services\Mcp\CalendarTools;
use PHPUnit\Framework\TestCase;

final class CalendarServiceTest extends TestCase
{
    private static function event(array $over = []): array
    {
        return $over + [
            'id' => 1, 'created_by' => 7, 'title' => 'Training', 'start_at' => '2026-11-02 18:00:00', 'end_at' => '2026-11-02 19:30:00',
            'all_day' => 0, 'lead_minutes' => 0, 'trail_minutes' => 0, 'recurrence' => 'none', 'recurrence_interval' => 1, 'recurrence_until' => null,
        ];
    }

    public function testSingleEventOccursOnlyOnItsDay(): void
    {
        self::assertSame(['2026-11-02'], CalendarService::occurrences(self::event(), '2026-11-01', '2026-11-30'));
        self::assertSame([], CalendarService::occurrences(self::event(), '2026-11-03', '2026-11-30'));
        self::assertSame([], CalendarService::occurrences(self::event(), '2026-11-01', '2026-11-30', ['2026-11-02']));
    }

    public function testWeeklyWithIntervalUntilAndExceptions(): void
    {
        $e = self::event(['recurrence' => 'weekly', 'recurrence_interval' => 2, 'recurrence_until' => '2026-12-31']);
        self::assertSame(['2026-11-16', '2026-11-30', '2026-12-28'],
            CalendarService::occurrences($e, '2026-11-10', '2027-01-31', ['2026-12-14']));
    }

    public function testDailyJumpsToRange(): void
    {
        $e = self::event(['recurrence' => 'daily', 'start_at' => '2020-01-01 08:00:00', 'end_at' => '2020-01-01 09:00:00']);
        self::assertSame(['2026-11-01', '2026-11-02', '2026-11-03'], CalendarService::occurrences($e, '2026-11-01', '2026-11-03'));
    }

    public function testMonthlyClampsToMonthEnd(): void
    {
        $e = self::event(['recurrence' => 'monthly', 'start_at' => '2026-01-31 10:00:00', 'end_at' => '2026-01-31 11:00:00']);
        self::assertSame(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30'], CalendarService::occurrences($e, '2026-01-01', '2026-04-30'));
        self::assertSame(['2027-02-28'], CalendarService::occurrences($e, '2027-02-01', '2027-02-28'));
    }

    public function testYearlyOnLeapDay(): void
    {
        $e = self::event(['recurrence' => 'yearly', 'start_at' => '2024-02-29 00:00:00', 'end_at' => '2024-03-01 00:00:00', 'all_day' => 1]);
        self::assertSame(['2025-02-28', '2028-02-29'], array_values(array_intersect(
            CalendarService::occurrences($e, '2025-01-01', '2028-12-31'), ['2025-02-28', '2028-02-29'])));
    }

    public function testInstanceIncludesLeadAndTrail(): void
    {
        $e = self::event(['recurrence' => 'weekly', 'lead_minutes' => 30, 'trail_minutes' => 45]);
        $i = CalendarService::instance($e, '2026-11-09');
        self::assertSame('2026-11-09 17:30:00', $i['lead_start']);
        self::assertSame('2026-11-09 18:00:00', $i['start']);
        self::assertSame('2026-11-09 19:30:00', $i['end']);
        self::assertSame('2026-11-09 20:15:00', $i['trail_end']);
    }

    public function testSegmentsSplitLeadAcrossMidnight(): void
    {
        $e = self::event(['start_at' => '2026-11-03 06:00:00', 'end_at' => '2026-11-03 08:00:00', 'lead_minutes' => 480, 'trail_minutes' => 60]);
        $s = CalendarService::segments(CalendarService::instance($e, '2026-11-03'));
        self::assertSame([['kind' => 'lead', 'from' => '22:00', 'to' => '24:00', 'all_day' => false]], $s['2026-11-02']);
        self::assertSame(['lead', 'main', 'trail'], array_column($s['2026-11-03'], 'kind'));
        self::assertSame('00:00', $s['2026-11-03'][0]['from']);
        self::assertSame('09:00', $s['2026-11-03'][2]['to']);
    }

    public function testSegmentsOfMultiDayAllDayEvent(): void
    {
        $e = self::event(['start_at' => '2026-11-06 00:00:00', 'end_at' => '2026-11-09 00:00:00', 'all_day' => 1, 'trail_minutes' => 240]);
        $s = CalendarService::segments(CalendarService::instance($e, '2026-11-06'), true);
        self::assertSame(['2026-11-06', '2026-11-07', '2026-11-08', '2026-11-09'], array_keys($s));
        self::assertTrue($s['2026-11-07'][0]['all_day']);
        self::assertSame([['kind' => 'trail', 'from' => '00:00', 'to' => '04:00', 'all_day' => false]], $s['2026-11-09']);
    }

    public function testColumnsPlaceOverlappingSideBySide(): void
    {
        $c = CalendarService::columns([
            'a' => [600, 720],   // 10–12
            'b' => [660, 690],   // 11–11:30 überschneidet a
            'c' => [700, 760],   // 11:40–12:40 überschneidet a, nicht b → Spalte von b
            'd' => [760, 800],   // berührt c nur → neue Gruppe
            'e' => [900, 960],   // allein
        ]);
        self::assertSame(['col' => 0, 'cols' => 2], $c['a']);
        self::assertSame(['col' => 1, 'cols' => 2], $c['b']);
        self::assertSame(['col' => 1, 'cols' => 2], $c['c']);
        self::assertSame(['col' => 0, 'cols' => 1], $c['d']);
        self::assertSame(['col' => 0, 'cols' => 1], $c['e']);
    }

    public function testColumnsSameStartLongerFirst(): void
    {
        $c = CalendarService::columns([1 => [600, 630], 2 => [600, 720], 3 => [600, 660]]);
        self::assertSame(0, $c[2]['col']);
        self::assertSame(1, $c[3]['col']);
        self::assertSame(2, $c[1]['col']);
        self::assertSame([3, 3, 3], array_column($c, 'cols'));
    }

    public function testDayLayoutKeepsLeadAndTrailInEventColumn(): void
    {
        $l = CalendarService::dayLayout([
            ['kind' => 'all', 'from' => '00:00', 'to' => '24:00', 'all_day' => true, 'idx' => 0],
            ['kind' => 'lead', 'from' => '09:30', 'to' => '10:00', 'all_day' => false, 'idx' => 1],
            ['kind' => 'main', 'from' => '10:00', 'to' => '11:00', 'all_day' => false, 'idx' => 1],
            ['kind' => 'trail', 'from' => '11:00', 'to' => '11:30', 'all_day' => false, 'idx' => 1],
            ['kind' => 'main', 'from' => '11:15', 'to' => '12:00', 'all_day' => false, 'idx' => 2],
            ['kind' => 'main', 'from' => '18:00', 'to' => '24:00', 'all_day' => false, 'idx' => 3],
        ]);
        self::assertCount(1, $l['all_day']);
        $byIdx = [];
        foreach ($l['timed'] as $s) {
            $byIdx[$s['idx']][] = [$s['kind'], $s['col'], $s['cols']];
        }
        // Nachlauf von 1 überschneidet 2 → nebeneinander, Vor-/Nachlauf in der Spalte des Termins
        self::assertSame([['lead', 0, 2], ['main', 0, 2], ['trail', 0, 2]], $byIdx[1]);
        self::assertSame([['main', 1, 2]], $byIdx[2]);
        self::assertSame([['main', 0, 1]], $byIdx[3]);
        self::assertSame(1440, end($l['timed'])['end']);
    }

    public function testInstancesTouchingFindsTrailOfPreviousDay(): void
    {
        $e = self::event(['start_at' => '2026-11-02 22:00:00', 'end_at' => '2026-11-02 23:00:00', 'trail_minutes' => 120]);
        self::assertCount(1, CalendarService::instancesTouching($e, '2026-11-03', '2026-11-03'));
        self::assertCount(0, CalendarService::instancesTouching($e, '2026-11-04', '2026-11-05'));
    }

    public function testParseAndFormatDuration(): void
    {
        self::assertSame(0, CalendarService::parseDuration(''));
        self::assertSame(90, CalendarService::parseDuration('90'));
        self::assertSame(90, CalendarService::parseDuration('1:30'));
        self::assertSame(120, CalendarService::parseDuration('2 h'));
        self::assertSame(90, CalendarService::parseDuration('1,5 Std'));
        self::assertSame(45, CalendarService::parseDuration('45 min'));
        self::assertSame(3060, CalendarService::parseDuration('2 Tage 3 h'));
        self::assertNull(CalendarService::parseDuration('bald'));
        foreach ([45, 90, 1440, 3060, 1500] as $m) {
            self::assertSame($m, CalendarService::parseDuration(CalendarService::formatDuration($m)), "Rundlauf $m");
        }
        self::assertSame('1 Std. 30 Min.', CalendarService::formatDuration(90));
        self::assertSame('2 Tage', CalendarService::formatDuration(2880));
    }

    public function testApplyTemplate(): void
    {
        $tpl = ['id' => 3, 'name' => 'Schwimmen', 'title' => null, 'duration_minutes' => 90, 'lead_minutes' => 20, 'trail_minutes' => 30,
                'all_day' => 0, 'color' => '#198754', 'location' => 'Hallenbad'];
        $e = CalendarService::applyTemplate($tpl, '2026-11-04 17:00');
        self::assertSame('Schwimmen', $e['title']);
        self::assertSame('2026-11-04 17:00:00', $e['start_at']);
        self::assertSame('2026-11-04 18:30:00', $e['end_at']);
        self::assertSame(20, $e['lead_minutes']);
        self::assertSame(3, $e['template_id']);

        $trip = CalendarService::applyTemplate(['name' => 'Wochenende', 'duration_minutes' => 2880, 'all_day' => 1], '2026-11-07 10:00');
        self::assertSame('2026-11-07 00:00:00', $trip['start_at']);
        self::assertSame('2026-11-09 00:00:00', $trip['end_at']);
    }

    public function testBuildEvent(): void
    {
        $d = CalendarService::buildEvent(['title' => ' Zahnarzt ', 'start' => '2026-11-05T09:15', 'end' => '2026-11-05 10:00', 'lead_minutes' => 25]);
        self::assertSame('Zahnarzt', $d['title']);
        self::assertSame('2026-11-05 09:15:00', $d['start_at']);
        self::assertSame(25, $d['lead_minutes']);
        self::assertSame('none', $d['recurrence']);

        $allDay = CalendarService::buildEvent(['title' => 'Urlaub', 'all_day' => true, 'start' => '2026-12-20', 'end' => '2026-12-27']);
        self::assertSame('2026-12-28 00:00:00', $allDay['end_at'], 'Ende exklusiv');
        self::assertSame('2026-11-05 10:15:00', CalendarService::buildEvent(['title' => 'X', 'start' => '2026-11-05 09:15'])['end_at']);

        foreach ([['title' => '', 'start' => '2026-11-05 09:00'], ['title' => 'X', 'start' => '2026-11-05'],
                  ['title' => 'X', 'start' => '2026-11-05 10:00', 'end' => '2026-11-05 09:00'],
                  ['title' => 'X', 'start' => '2026-11-05 10:00', 'recurrence' => 'hourly'],
                  ['title' => 'X', 'start' => '2026-11-05 10:00', 'recurrence' => 'weekly', 'recurrence_until' => '2026-01-01']] as $bad) {
            try {
                CalendarService::buildEvent($bad);
                self::fail('Ungültig: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testRoleAndEditRights(): void
    {
        $e = self::event();
        self::assertSame('owner', CalendarService::role($e, 7));
        self::assertSame('participant', CalendarService::role($e, 8));
        self::assertTrue(CalendarService::canEdit($e, 7, false));
        self::assertFalse(CalendarService::canEdit($e, 8, false));
        self::assertTrue(CalendarService::canEdit($e, 8, true));
    }

    public function testOpenBudgetDatesMovesOverdueToTomorrow(): void
    {
        $r = CalendarService::openBudgetDates(['2026-10-20', '2026-10-27', '2026-11-03'], ['2026-10-20'], '2026-10-31');
        self::assertSame([
            ['date' => '2026-11-01', 'occurrence' => '2026-10-27', 'overdue' => true],
            ['date' => '2026-11-03', 'occurrence' => '2026-11-03', 'overdue' => false],
        ], $r);
    }

    public function testBudgetToolsOnlyWhenEnabled(): void
    {
        $tools = ['list_calendar' => [], 'create_event' => [], 'set_event_budget' => [], 'book_event_budget' => []];
        self::assertSame(['list_calendar', 'create_event'], array_keys(CalendarTools::visibleTools($tools, false)));
        self::assertCount(4, CalendarTools::visibleTools($tools, true));
    }

    public function testTextColor(): void
    {
        self::assertSame('#fff', CalendarService::textColor('#0d6efd'));
        self::assertSame('#212529', CalendarService::textColor('#ffc107'));
    }
}
