<?php
/**
 * Zeitraster für Wochen- und Tagesansicht: Höhe eines Termins proportional zur Dauer, gleichzeitige Termine
 * nebeneinander (CalendarService::dayLayout). Ganztägige Termine stehen in einer Zeile darüber.
 * Der ganze Tag (0–24 Uhr) ist scrollbar, beim Öffnen steht er auf 7 Uhr bzw. dem ersten Termin.
 * Eigene Termine lassen sich ziehen (public/assets/js/calendar.js, calTimegrid): verschieben, Beginn/Ende und
 * Vor-/Nachlauf über Griffe ändern – nur Termine, die an einem Tag liegen (inkl. Vor-/Nachlauf).
 * Variablen: $days (Datumsliste), $byDay, $occurrences, $today, $userQuery (Mitglied für Links, ggf. leer)
 */
use App\Core\Auth;
use App\Core\View;
use App\Services\CalendarService;

$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$layouts = [];
$firstStart = 7 * 60;
foreach ($days as $d) {
    $layouts[$d] = CalendarService::dayLayout($byDay[$d] ?? []);
    foreach ($layouts[$d]['timed'] as $s) {
        $firstStart = min($firstStart, $s['start']);
    }
}
$hasAllDay = (bool) array_filter($layouts, fn ($l) => $l['all_day']);
$pct = fn (int $min) => round($min / 1440 * 100, 4) . '%';
$nowMin = (int) date('G') * 60 + (int) date('i');

// Ziehbar: eigener (bzw. als Admin jeder) Termin, nicht ganztägig, samt Vor-/Nachlauf an einem Tag
$draggable = [];
foreach ($occurrences as $idx => $o) {
    $ev = $o['event'];
    $lastMinute = date('Y-m-d', strtotime($o['trail_end'] . ' -1 minute'));
    $draggable[$idx] = !$ev['all_day'] && substr($o['lead_start'], 0, 10) === $lastMinute
        && CalendarService::canEdit($ev, Auth::id(), Auth::isAdmin());
}
?>
<div class="cal-tg cal-tg-days-<?= count($days) ?>" style="--days: <?= count($days) ?>" data-scroll="<?= max(0, $firstStart - 30) ?>">
    <div class="cal-tg-scroll">
        <div class="cal-tg-sticky">
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
        </div>
        <div class="cal-tg-row cal-tg-body">
            <div class="cal-tg-axis">
                <?php for ($h = 1; $h < 24; $h++): ?>
                    <span style="top: <?= $pct($h * 60) ?>"><?= sprintf('%02d:00', $h) ?></span>
                <?php endfor; ?>
            </div>
            <?php foreach ($days as $d): ?>
                <div class="cal-tg-col <?= $d === $today ? 'cal-today' : '' ?>" data-date="<?= e($d) ?>">
                    <?php for ($h = 0; $h < 24; $h++): ?>
                        <a class="cal-tg-slot" style="top: <?= $pct($h * 60) ?>; height: <?= $pct(60) ?>"
                           href="<?= e(url('/calendar/new', ['date' => $d, 'time' => sprintf('%02d:00', $h)])) ?>"
                           title="Neuer Termin am <?= e(date_de($d)) ?> um <?= sprintf('%02d:00', $h) ?>" aria-label="Neuer Termin <?= sprintf('%02d:00', $h) ?>"></a>
                    <?php endfor; ?>
                    <?php foreach ($layouts[$d]['timed'] as $s):
                        $o = $occurrences[$s['idx']];
                        $pos = 'top: ' . $pct($s['start']) . '; height: ' . $pct($s['end'] - $s['start'])
                             . '; left: calc(' . $s['col'] . ' * 100% / ' . $s['cols'] . '); width: calc(100% / ' . $s['cols'] . ' - 2px)';
                        $drag = $draggable[$s['idx']] ? [
                            'occ'   => $s['idx'],
                            'id'    => (int) $o['event']['id'],
                            'date'  => $o['date'],
                            'start' => CalendarService::minutes(substr($o['start'], 11, 5)),
                            'end'   => substr($o['end'], 0, 10) > $o['date'] ? 1440 : CalendarService::minutes(substr($o['end'], 11, 5)),
                            'lead'  => (int) $o['event']['lead_minutes'],
                            'trail' => (int) $o['event']['trail_minutes'],
                            'rec'   => $o['event']['recurrence'] !== 'none',
                        ] : null; ?>
                        <?= View::partial('calendar/chip', ['o' => $o, 's' => $s, 'mode' => 'block', 'pos' => $pos, 'drag' => $drag]) ?>
                    <?php endforeach; ?>
                    <?php if ($d === $today): ?>
                        <div class="cal-tg-now" style="top: <?= $pct($nowMin) ?>" title="Jetzt"></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<dialog class="cal-dialog" id="cal-scope-dialog">
    <form method="dialog">
        <p class="mb-3"><strong>Terminserie ändern</strong><br><span class="small text-body-secondary">Soll nur dieser Termin oder die ganze Serie geändert werden?</span></p>
        <div class="d-grid gap-2">
            <button class="btn btn-primary" value="one">Nur diesen Termin</button>
            <button class="btn btn-outline-primary" value="series">Ganze Serie</button>
            <button class="btn btn-outline-secondary" value="cancel">Abbrechen</button>
        </div>
    </form>
</dialog>
