<?php
use App\Core\View;
use App\Services\CalendarService;

$fmt = fn (string $dt) => date_de(substr($dt, 0, 10)) . ' ' . substr($dt, 11, 5);
$allDay = (bool) $event['all_day'];
$lastDay = date('Y-m-d', strtotime($instance['end'] . ' -1 second'));
$when = $allDay
    ? (substr($instance['start'], 0, 10) === $lastDay ? date_de($lastDay) . ', ganztägig' : date_de(substr($instance['start'], 0, 10)) . ' – ' . date_de($lastDay) . ', ganztägig')
    : (substr($instance['start'], 0, 10) === substr($instance['end'], 0, 10)
        ? date_de($date) . ', ' . substr($instance['start'], 11, 5) . '–' . substr($instance['end'], 11, 5) . ' Uhr'
        : $fmt($instance['start']) . ' – ' . $fmt($instance['end']));
$color = e($event['color']);
?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3" style="border-top: .3rem solid <?= $color ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap gap-2 align-items-start mb-2">
                    <h2 class="h4 mb-0 flex-grow-1"><?= e($event['title']) ?></h2>
                    <?php if ($role === 'owner'): ?>
                        <span class="badge text-bg-primary"><i class="bi bi-person-fill"></i> eigener Termin</span>
                    <?php elseif ($isParticipant): ?>
                        <span class="badge text-bg-light border"><i class="bi bi-person-check"></i> Teilnehmer · von <?= e($event['creator_name'] ?? '–') ?></span>
                    <?php else: ?>
                        <span class="badge text-bg-light border">von <?= e($event['creator_name'] ?? '–') ?></span>
                    <?php endif; ?>
                </div>
                <p class="mb-1"><i class="bi bi-clock"></i> <?= e($when) ?></p>
                <?php if ($event['recurrence'] !== 'none'): ?>
                    <p class="mb-1 small text-body-secondary"><i class="bi bi-arrow-repeat"></i>
                        <?= e((int) $event['recurrence_interval'] > 1 ? 'alle ' . (int) $event['recurrence_interval'] . ' × ' . CalendarService::RECURRENCE[$event['recurrence']] : CalendarService::RECURRENCE[$event['recurrence']]) ?>
                        <?= $event['recurrence_until'] ? ' bis ' . e(date_de($event['recurrence_until'])) : '' ?></p>
                <?php endif; ?>
                <?php if ($event['location']): ?><p class="mb-1"><i class="bi bi-geo-alt"></i> <?= e($event['location']) ?></p><?php endif; ?>
                <p class="mb-2"><i class="bi bi-people"></i> <?= e(implode(', ', array_column($participants, 'name'))) ?></p>
                <?php if ($event['note']): ?><p class="mb-2" style="white-space: pre-line"><?= e($event['note']) ?></p><?php endif; ?>

                <?php if ((int) $event['lead_minutes'] || (int) $event['trail_minutes']): ?>
                    <h3 class="h6 mt-3">Ablauf</h3>
                    <ul class="cal-timeline" style="--c: <?= $color ?>">
                        <?php if ((int) $event['lead_minutes']): ?>
                            <li class="cal-lead"><span class="cal-bar"></span><span><i class="bi bi-box-arrow-in-right"></i> Vorlauf (Anreise) · <?= e($fmt($instance['lead_start'])) ?> – <?= e(substr($instance['start'], 11, 5)) ?> · <?= e(CalendarService::formatDuration((int) $event['lead_minutes'])) ?></span></li>
                        <?php endif; ?>
                        <li><span class="cal-bar"></span><span class="fw-semibold"><?= e($event['title']) ?> · <?= e($when) ?></span></li>
                        <?php if ((int) $event['trail_minutes']): ?>
                            <li class="cal-trail"><span class="cal-bar"></span><span><i class="bi bi-box-arrow-right"></i> Nachlauf (Abreise) · <?= e($fmt($instance['end'])) ?> – <?= e(substr($instance['trail_end'], 11, 5)) ?><?= substr($instance['trail_end'], 0, 10) !== substr($instance['end'], 0, 10) ? ' (' . e(date_de(substr($instance['trail_end'], 0, 10))) . ')' : '' ?> · <?= e(CalendarService::formatDuration((int) $event['trail_minutes'])) ?></span></li>
                        <?php endif; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?php if ($canEdit): ?>
                <div class="card-footer bg-transparent d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-primary btn-sm" href="<?= e(url("/calendar/{$event['id']}/edit")) ?>"><i class="bi bi-pencil"></i> Bearbeiten</a>
                    <form method="post" action="<?= e(url("/calendar/{$event['id']}/delete")) ?>" class="d-flex gap-2"
                          onsubmit="return confirm(event.submitter && event.submitter.value === 'one' ? 'Nur diesen Termin löschen?' : 'Termin<?= $event['recurrence'] !== 'none' ? 'serie' : '' ?> löschen?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="date" value="<?= e($date) ?>">
                        <?php if ($event['recurrence'] !== 'none'): ?>
                            <button class="btn btn-outline-danger btn-sm" name="scope" value="one"><i class="bi bi-trash"></i> Nur diesen Termin</button>
                            <button class="btn btn-outline-danger btn-sm" name="scope" value="series">Ganze Serie</button>
                        <?php else: ?>
                            <button class="btn btn-outline-danger btn-sm" name="scope" value="series"><i class="bi bi-trash"></i> Löschen</button>
                        <?php endif; ?>
                    </form>
                    <a class="btn btn-outline-secondary btn-sm ms-auto" href="<?= e(url('/calendar/new', ['date' => $date])) ?>"><i class="bi bi-plus-lg"></i> Neuer Termin</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($upcoming): ?>
            <div class="card mb-3">
                <div class="card-header bg-transparent"><strong>Nächste Termine der Serie</strong></div>
                <div class="card-body small d-flex flex-wrap gap-2">
                    <?php foreach (array_slice($upcoming, 0, 12) as $d): ?>
                        <a class="btn btn-sm <?= $d === $date ? 'btn-primary' : 'btn-outline-secondary' ?>" href="<?= e(url("/calendar/{$event['id']}", ['date' => $d])) ?>"><?= e(date_de($d)) ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <?php if ($isParticipant): ?>
            <div class="card mb-3">
                <div class="card-header bg-transparent"><strong><i class="bi bi-wallet2"></i> Mein Budget</strong>
                    <?php if ($event['recurrence'] !== 'none'): ?><span class="small text-body-secondary">– je Termin</span><?php endif; ?></div>
                <div class="card-body">
                    <?php if ($budget): ?>
                        <?php if ($booking): ?>
                            <div class="alert alert-success small mb-3"><i class="bi bi-check2-circle"></i>
                                Am <?= e(date_de($date)) ?> gebucht: <strong><?= money(abs((float) $booking['tx_amount'])) ?></strong> auf <?= e($booking['account_name'] ?? '–') ?>
                                · <a href="<?= e(url("/transactions/{$booking['transaction_id']}/edit")) ?>">Buchung ansehen</a>
                                <div class="text-body-secondary">Wird die Buchung gelöscht, ist das Budget wieder offen.</div>
                            </div>
                        <?php else: ?>
                            <div class="d-flex gap-2 align-items-center mb-3">
                                <div class="flex-grow-1 small">Offen für <?= e(date_de($date)) ?>: <strong><?= money($budget['amount']) ?></strong>
                                    <?= $budget['account_id'] ? '' : '<br><span class="text-warning-emphasis">Ohne Konto – fließt nicht in die Prognose ein.</span>' ?></div>
                                <a class="btn btn-primary" href="<?= e(url("/calendar/{$event['id']}/book", ['date' => $date])) ?>"><i class="bi bi-cash-coin"></i> Als Ausgabe buchen</a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <form method="post" action="<?= e(url("/calendar/{$event['id']}/budget")) ?>">
                        <?= csrf_field() ?>
                        <?= View::partial('calendar/budget_fields', ['budget' => $budget, 'accounts' => $accounts, 'categories' => $categories, 'defaultAccount' => null]) ?>
                        <button class="btn btn-outline-primary btn-sm"><?= $budget ? 'Budget ändern' : 'Budget planen' ?></button>
                        <span class="form-text ms-2">Leerer Betrag entfernt das Budget.</span>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($allBudgets): ?>
            <div class="card">
                <div class="card-header bg-transparent"><strong>Budgets aller Teilnehmer</strong></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($allBudgets as $b): ?>
                        <li class="list-group-item d-flex justify-content-between"><span><?= e($b['user_name']) ?><?= $b['account_name'] ? ' · ' . e($b['account_name']) : '' ?></span><strong><?= money($b['amount']) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
