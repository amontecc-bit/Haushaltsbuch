<?php
/**
 * Ein Abschnitt eines Termins im Kalender.
 * Variablen: $o (Vorkommen aus CalendarService::forUser), $s (Abschnitt aus segments()), $mode 'chip' | 'row' | 'block',
 *            $range (Chip mit Zeitspanne statt nur Beginn, z. B. Wochenansicht),
 *            $pos (nur 'block': CSS für Lage und Größe im Zeitraster)
 */
use App\Services\CalendarService;

$ev = $o['event'];
$range ??= false;
$kind = $s['kind'];
$isOwner = $o['role'] === 'owner';
// Fortsetzung eines mehrtägigen Termins (Abschnitt beginnt um Mitternacht, der Termin nicht)
$continued = $kind === 'main' && !$s['all_day'] && $s['from'] === '00:00' && substr($o['start'], 11, 5) !== '00:00';
$time = $s['all_day'] ? 'ganztägig' : ($s['from'] . '–' . $s['to']);
$kindLabel = ['lead' => 'Vorlauf', 'trail' => 'Nachlauf', 'main' => ''][$kind];
$kindIcon = ['lead' => 'box-arrow-in-right', 'trail' => 'box-arrow-right', 'main' => ''][$kind];
$roleText = $isOwner ? 'eigener Termin' : 'Teilnehmer · von ' . ($ev['creator_name'] ?? '–');
$budgetText = '';
if ($o['budget'] && $kind === 'main') {
    $budgetText = $o['booking'] ? 'Budget gebucht' : 'Budget ' . money($o['budget']['amount']) . ' offen';
}
$tooltip = trim(($kindLabel ? $kindLabel . ' · ' : '') . $ev['title'] . ' · ' . $time . ' · ' . $roleText . ($budgetText ? ' · ' . $budgetText : ''));
$classes = 'cal-' . $kind . ($isOwner ? ' cal-owner' : ' cal-participant');
$style = '--c: ' . $ev['color'] . '; --fg: ' . CalendarService::textColor($ev['color']);
$href = url('/calendar/' . $ev['id'], ['date' => $o['date']]);
?>
<?php if ($mode === 'block'): ?>
<?php // Kurze Termine (< 1 h): nur der Titel – die Zeit zeigen Lage im Raster und Tooltip
$short = $kind === 'main' && isset($s['start'], $s['end']) && $s['end'] - $s['start'] < 60; ?>
<a class="cal-ev <?= e($classes) ?><?= $short ? ' cal-ev-short' : '' ?>" style="<?= e($style . '; ' . $pos) ?>" href="<?= e($href) ?>" title="<?= e($tooltip) ?>">
    <?php if ($kind !== 'main'): ?>
        <span class="cal-ev-time"><i class="bi bi-<?= $kindIcon ?>"></i> <?= e($kindLabel) ?></span>
    <?php else: ?>
        <span class="cal-ev-time"><?php if (!$isOwner): ?><i class="bi bi-person-check" aria-label="Teilnehmer"></i> <?php endif; ?><?= e($time) ?>
            <?php if ($budgetText): ?><i class="bi bi-<?= $o['booking'] ? 'check2-circle' : 'wallet2' ?>" aria-label="<?= e($budgetText) ?>"></i><?php endif; ?></span>
        <span class="cal-ev-title"><?= e($ev['title']) ?></span>
        <?php if ($ev['location']): ?><span class="cal-ev-loc"><?= e($ev['location']) ?></span><?php endif; ?>
    <?php endif; ?>
</a>
<?php elseif ($mode === 'chip'): ?>
<a class="cal-chip <?= e($classes) ?>" style="<?= e($style) ?>" href="<?= e($href) ?>" title="<?= e($tooltip) ?>">
    <?php if ($kind !== 'main'): ?>
        <i class="bi bi-<?= $kindIcon ?>"></i> <?= e($kindLabel) ?><?= $s['all_day'] ? '' : ' ' . e($range ? $time : $s['from']) ?>
    <?php else: ?>
        <?php if (!$isOwner): ?><i class="bi bi-person-check" aria-label="Teilnehmer"></i><?php endif; ?>
        <?php if ($continued): ?><i class="bi bi-arrow-return-right" aria-label="Fortsetzung"></i><?php if ($range): ?> <span class="fw-semibold">bis <?= e($s['to']) ?></span><?php endif; ?>
        <?php elseif (!$s['all_day']): ?><span class="fw-semibold"><?= e($range ? $time : $s['from']) ?></span><?php endif; ?>
        <?= e($ev['title']) ?>
        <?php if ($budgetText): ?><i class="bi bi-<?= $o['booking'] ? 'check2-circle' : 'wallet2' ?>" aria-label="<?= e($budgetText) ?>"></i><?php endif; ?>
    <?php endif; ?>
</a>
<?php else: ?>
<a class="cal-row <?= e($classes) ?>" style="<?= e($style) ?>" href="<?= e($href) ?>">
    <span class="cal-bar"></span>
    <span class="cal-time"><?= e($time) ?></span>
    <span class="flex-grow-1 text-truncate">
        <?php if ($kind !== 'main'): ?>
            <i class="bi bi-<?= $kindIcon ?>"></i> <?= e($kindLabel) ?> · <?= e($ev['title']) ?>
        <?php else: ?>
            <span class="fw-semibold"><?= e($ev['title']) ?></span>
            <?php if ($ev['location']): ?><span class="small text-body-secondary">· <?= e($ev['location']) ?></span><?php endif; ?>
            <br><span class="small text-body-secondary">
                <?php if ($isOwner): ?><i class="bi bi-person-fill"></i> eigener Termin<?php else: ?><i class="bi bi-person-check"></i> Teilnehmer · von <?= e($ev['creator_name'] ?? '–') ?><?php endif; ?>
                <?php if (count($o['participants']) > 1): ?>· <?= e(implode(', ', array_column($o['participants'], 'name'))) ?><?php endif; ?>
            </span>
        <?php endif; ?>
    </span>
    <?php if ($budgetText): ?>
        <span class="badge <?= $o['booking'] ? 'text-bg-success' : 'text-bg-warning' ?>"><i class="bi bi-<?= $o['booking'] ? 'check2' : 'wallet2' ?>"></i>
            <?= $o['booking'] ? 'gebucht' : money($o['budget']['amount']) ?></span>
    <?php endif; ?>
</a>
<?php endif; ?>
