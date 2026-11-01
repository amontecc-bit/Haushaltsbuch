<?php
/**
 * Zeitraster für Wochen- und Tagesansicht: Höhe eines Termins proportional zur Dauer, gleichzeitige Termine
 * nebeneinander (CalendarService::dayLayout). Ganztägige Termine stehen in einer Zeile darüber.
 * Variablen: $days (Datumsliste), $byDay, $occurrences, $today, $userQuery (Mitglied für Links, ggf. leer)
 */
use App\Core\View;
use App\Services\CalendarService;

$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$layouts = [];
$minStart = 7 * 60;
$maxEnd = 20 * 60;
foreach ($days as $d) {
    $layouts[$d] = CalendarService::dayLayout($byDay[$d] ?? []);
    foreach ($layouts[$d]['timed'] as $s) {
        $minStart = min($minStart, $s['start']);
        $maxEnd = max($maxEnd, $s['end']);
    }
}
// Sichtbarer Bereich: mindestens 7–20 Uhr, erweitert auf alle Termine (volle Stunden)
$fromHour = intdiv($minStart, 60);
$toHour = min(24, (int) ceil($maxEnd / 60));
$range = ($toHour - $fromHour) * 60;
$hasAllDay = (bool) array_filter($layouts, fn ($l) => $l['all_day']);
$pct = fn (int $min) => round(($min - $fromHour * 60) / $range * 100, 4) . '%';
$nowMin = (int) date('G') * 60 + (int) date('i');
?>
<div class="cal-tg cal-tg-days-<?= count($days) ?>" style="--days: <?= count($days) ?>; --hours: <?= $toHour - $fromHour ?>">
    <div class="cal-tg-row cal-tg-headrow">
        <div class="cal-tg-axis"></div>
        <?php foreach ($days as $d): ?>
            <a class="cal-tg-head <?= $d === $today ? 'cal-today' : '' ?>" href="<?= e(url('/calendar', ['view' => 'day', 'date' => $d] + $userQuery)) ?>"
               title="Tagesansicht"><?= e($weekdays[(int) date('N', strtotime($d)) - 1]) ?> <span class="cal-num"><?= (int) substr($d, 8, 2) ?></span></a>
        <?php endforeach; ?>
    </div>
    <?php if ($hasAllDay): ?>
        <div class="cal-tg-row cal-tg-allday">
            <div class="cal-tg-axis">ganztägig</div>
            <?php foreach ($days as $d): ?>
                <div class="cal-tg-cell">
                    <?php foreach ($layouts[$d]['all_day'] as $s): ?>
                        <?= View::partial('calendar/chip', ['o' => $occurrences[$s['idx']], 's' => $s, 'mode' => 'chip']) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="cal-tg-row cal-tg-body">
        <div class="cal-tg-axis">
            <?php for ($h = $fromHour; $h < $toHour; $h++): ?>
                <span style="top: <?= $pct($h * 60) ?>"><?= sprintf('%02d:00', $h) ?></span>
            <?php endfor; ?>
        </div>
        <?php foreach ($days as $d): ?>
            <div class="cal-tg-col <?= $d === $today ? 'cal-today' : '' ?>">
                <?php for ($h = $fromHour; $h < $toHour; $h++): ?>
                    <a class="cal-tg-slot" style="top: <?= $pct($h * 60) ?>; height: <?= $pct($fromHour * 60 + 60) ?>"
                       href="<?= e(url('/calendar/new', ['date' => $d, 'time' => sprintf('%02d:00', $h)])) ?>"
                       title="Neuer Termin am <?= e(date_de($d)) ?> um <?= sprintf('%02d:00', $h) ?>" aria-label="Neuer Termin <?= sprintf('%02d:00', $h) ?>"></a>
                <?php endfor; ?>
                <?php foreach ($layouts[$d]['timed'] as $s):
                    $pos = 'top: ' . $pct($s['start']) . '; height: ' . $pct($fromHour * 60 + $s['end'] - $s['start'])
                         . '; left: calc(' . $s['col'] . ' * 100% / ' . $s['cols'] . '); width: calc(100% / ' . $s['cols'] . ' - 2px)'; ?>
                    <?= View::partial('calendar/chip', ['o' => $occurrences[$s['idx']], 's' => $s, 'mode' => 'block', 'pos' => $pos]) ?>
                <?php endforeach; ?>
                <?php if ($d === $today && $nowMin >= $fromHour * 60 && $nowMin <= $toHour * 60): ?>
                    <div class="cal-tg-now" style="top: <?= $pct($nowMin) ?>" title="Jetzt"></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>
