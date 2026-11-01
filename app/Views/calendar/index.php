<?php
use App\Core\View;

$weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
$today = date('Y-m-d');
$days = [];
for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $days[] = $d;
}
// Liste (mobil bzw. Listenansicht): nur Tage mit Einträgen, in der Monatsansicht nur der Monat selbst
$listDays = array_values(array_filter($days, fn ($d) => !empty($byDay[$d]) && ($view !== 'month' || substr($d, 0, 7) === $month)));
$viewLabels = ['day' => 'Tag', 'week' => 'Woche', 'month' => 'Monat', 'list' => 'Liste'];
$userQuery = $isSelf ? [] : ['user' => $viewUser];
$viewName = '';
foreach ($members as $m) {
    if ((int) $m['id'] === $viewUser) {
        $viewName = $m['name'];
    }
}
?>
<div class="cal-toolbar">
    <div class="btn-group">
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calendar', $prev)) ?>" aria-label="Zurück"><i class="bi bi-chevron-left"></i></a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calendar', $base)) ?>">Heute</a>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calendar', $next)) ?>" aria-label="Weiter"><i class="bi bi-chevron-right"></i></a>
    </div>
    <h2><?= e($heading) ?></h2>
    <div class="btn-group ms-auto" role="group" aria-label="Ansicht">
        <?php foreach ($viewLabels as $v => $label): ?>
            <a class="btn btn-sm <?= $view === $v ? 'btn-primary' : 'btn-outline-primary' ?>"
               href="<?= e(url('/calendar', ['view' => $v, 'date' => $date] + $userQuery)) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (count($members) > 1): ?>
        <form method="get" action="<?= e(url('/calendar')) ?>">
            <input type="hidden" name="view" value="<?= e($view) ?>">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <select name="user" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Kalender von">
                <?php foreach ($members as $m): ?>
                    <option value="<?= (int) $m['id'] ?>" <?= selected($m['id'], $viewUser) ?>><?= (int) $m['id'] === (int) $user['id'] ? 'Mein Kalender' : 'Kalender von ' . e($m['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
    <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/calendar/templates')) ?>"><i class="bi bi-layout-text-window"></i> Vorlagen</a>
</div>

<?php if (!$isSelf): ?>
    <div class="alert alert-info small py-2"><i class="bi bi-eye"></i> Du siehst den Kalender von <strong><?= e($viewName) ?></strong>
        – „eigener Termin“/„Teilnehmer“ beziehen sich auf <?= e($viewName) ?>.</div>
<?php endif; ?>

<div class="cal-legend">
    <span><span class="cal-chip cal-main cal-owner" style="--c:#0d6efd">Termin</span> eigener Termin</span>
    <span><span class="cal-chip cal-main cal-participant" style="--c:#0d6efd"><i class="bi bi-person-check"></i> Termin</span> nur Teilnehmer</span>
    <span><span class="cal-chip cal-lead cal-owner" style="--c:#0d6efd"><i class="bi bi-box-arrow-in-right"></i> Vorlauf</span>/<span class="cal-chip cal-trail cal-owner" style="--c:#0d6efd"><i class="bi bi-box-arrow-right"></i> Nachlauf</span> Anreise/Abreise</span>
    <span><i class="bi bi-wallet2"></i> Budget offen · <i class="bi bi-check2-circle"></i> gebucht</span>
</div>

<?php if ($view === 'month'): ?>
    <div class="card d-none d-md-block mb-3">
        <div class="cal-grid">
            <?php foreach ($weekdays as $wd): ?>
                <div class="cal-head"><?= e($wd) ?></div>
            <?php endforeach; ?>
            <?php foreach ($days as $d): ?>
                <div class="cal-day <?= $d === $today ? 'cal-today' : '' ?> <?= substr($d, 0, 7) !== $month ? 'cal-other' : '' ?>">
                    <a class="cal-num" href="<?= e(url('/calendar', ['view' => 'day', 'date' => $d] + $userQuery)) ?>" title="Tagesansicht <?= e(date_de($d)) ?>"><?= (int) substr($d, 8, 2) ?></a>
                    <a class="cal-add" href="<?= e(url('/calendar/new', ['date' => $d])) ?>" title="Neuer Termin am <?= e(date_de($d)) ?>" aria-label="Neuer Termin am <?= e(date_de($d)) ?>"><i class="bi bi-plus-lg"></i></a>
                    <?php foreach ($byDay[$d] ?? [] as $s): ?>
                        <?= View::partial('calendar/chip', ['o' => $occurrences[$s['idx']], 's' => $s, 'mode' => 'chip']) ?>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php elseif ($view === 'week' || $view === 'day'): ?>
    <div class="card mb-3 <?= $view === 'week' ? 'd-none d-md-block' : '' ?>">
        <?= View::partial('calendar/timegrid', ['days' => $days, 'byDay' => $byDay, 'occurrences' => $occurrences, 'today' => $today, 'userQuery' => $userQuery]) ?>
    </div>
<?php endif; ?>

<?php if ($view !== 'day'): ?>
<div class="card <?= $view !== 'list' ? 'd-md-none' : '' ?>">
    <?php foreach ($listDays as $d): ?>
        <div class="cal-list-day <?= $d === $today ? 'cal-today' : '' ?>"><?= e($weekdays[(int) date('N', strtotime($d)) - 1]) ?>, <?= e(date_de($d)) ?><?= $d === $today ? ' · heute' : '' ?></div>
        <?php foreach ($byDay[$d] as $s): ?>
            <?= View::partial('calendar/chip', ['o' => $occurrences[$s['idx']], 's' => $s, 'mode' => 'row']) ?>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php if (!$listDays): ?>
        <div class="card-body text-body-secondary small">Keine Termine in diesem Zeitraum. <a href="<?= e(url('/calendar/new', ['date' => $date])) ?>">Termin anlegen</a></div>
    <?php endif; ?>
</div>
<?php endif; ?>
