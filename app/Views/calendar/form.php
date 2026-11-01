<?php
use App\Core\View;
use App\Services\CalendarService;

$isEdit = !empty($event['id']);
$action = $isEdit ? url("/calendar/{$event['id']}") : url('/calendar');
$allDay = (bool) $event['all_day'];
$lastDay = $allDay ? date('Y-m-d', strtotime($event['end_at'] . ' -1 day')) : substr($event['end_at'], 0, 10);
$ownerId = (int) ($event['created_by'] ?? $user['id']);
$showBudget = !$isEdit || in_array((int) $user['id'], array_map('intval', $participants), true);
$config = [
    'event' => [
        'title'      => (string) $event['title'],
        'location'   => (string) ($event['location'] ?? ''),
        'allDay'     => $allDay,
        'startDate'  => substr($event['start_at'], 0, 10),
        'startTime'  => substr($event['start_at'], 11, 5),
        'endDate'    => $lastDay,
        'endTime'    => substr($event['end_at'], 11, 5),
        'lead'       => CalendarService::formatDuration((int) $event['lead_minutes']),
        'trail'      => CalendarService::formatDuration((int) $event['trail_minutes']),
        'color'      => $event['color'] ?: '#0d6efd',
        'templateId' => (string) ($event['template_id'] ?? ''),
        'recurrence' => $event['recurrence'] ?? 'none',
    ],
    'budget'         => isset($budget['amount']) ? money_input($budget['amount']) : '',
    'budgetAccount'  => (string) ($budget['account_id'] ?? $defaultAccount ?? ''),
    'budgetCategory' => (string) ($budget['category_id'] ?? ''),
    'templates'      => array_map(fn ($t) => [
        'id' => (string) $t['id'], 'name' => $t['name'], 'title' => (string) $t['title'], 'location' => (string) $t['location'],
        'duration' => (int) $t['duration_minutes'], 'lead' => (int) $t['lead_minutes'], 'trail' => (int) $t['trail_minutes'],
        'allDay' => (bool) $t['all_day'], 'color' => $t['color'],
        'budget' => $t['budget_amount'] !== null ? money_input($t['budget_amount']) : '',
        'budgetAccount' => (string) ($t['budget_account_id'] ?? ''), 'budgetCategory' => (string) ($t['budget_category_id'] ?? ''),
    ], $templates),
];
?>
<div class="row justify-content-center">
    <div class="col-lg-9 col-xl-7">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($action) ?>" class="card" x-data="calendarForm(<?= e(json_encode($config)) ?>)">
            <?= csrf_field() ?>
            <input type="hidden" name="template_id" :value="templateId">
            <?php if (!empty($date)): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php endif; ?>
            <?php if (!empty($fromTemplates)): ?><input type="hidden" name="from" value="templates"><?php endif; ?>
            <div class="card-body">
                <?php if ($templates): ?>
                    <div class="mb-3">
                        <label class="form-label" for="tpl">Vorlage</label>
                        <select class="form-select" id="tpl" @change="applyTemplate($event.target.value)">
                            <option value="">– ohne Vorlage –</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= (int) $t['id'] ?>" <?= selected($t['id'], $event['template_id']) ?>><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Übernimmt Dauer, Vor- und Nachlauf (und ggf. Ort, Farbe, Budget). <a href="<?= e(url('/calendar/templates')) ?>">Vorlagen verwalten</a></div>
                    </div>
                <?php else: ?>
                    <div class="form-text mb-3"><i class="bi bi-lightbulb"></i> Tipp: Mit <a href="<?= e(url('/calendar/templates')) ?>">Vorlagen</a> sind Dauer, Anreise und Abreise für wiederkehrende Terminarten schnell gesetzt.</div>
                <?php endif; ?>

                <div class="mb-3">
                    <label class="form-label" for="title">Titel</label>
                    <div class="input-group">
                        <input class="form-control form-control-lg" id="title" name="title" x-model="title" required maxlength="190" placeholder="z. B. Schwimmtraining, Zahnarzt" <?= $isEdit ? '' : 'autofocus' ?>>
                        <input type="color" class="form-control form-control-color flex-grow-0" style="width: 3.5rem" name="color" x-model="color" title="Farbe">
                    </div>
                </div>

                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="all_day" name="all_day" value="1" x-model="allDay" @change="durationMin = diff()">
                    <label class="form-check-label" for="all_day">Ganztägig</label>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-7 col-sm-3">
                        <label class="form-label small" for="start_date">Beginn</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" x-model="startDate" required>
                    </div>
                    <div class="col-5 col-sm-3" x-show="!allDay">
                        <label class="form-label small" for="start_time">Uhrzeit</label>
                        <input type="time" class="form-control" id="start_time" name="start_time" x-model="startTime">
                    </div>
                    <div class="col-7 col-sm-3">
                        <label class="form-label small" for="end_date">Ende</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" x-model="endDate">
                    </div>
                    <div class="col-5 col-sm-3" x-show="!allDay">
                        <label class="form-label small" for="end_time">Uhrzeit</label>
                        <input type="time" class="form-control" id="end_time" name="end_time" x-model="endTime">
                    </div>
                </div>
                <div class="form-text mb-3" x-text="durationText()"></div>

                <div class="row g-2 mb-1">
                    <div class="col-6">
                        <label class="form-label small" for="lead"><i class="bi bi-box-arrow-in-right"></i> Vorlauf (Anreise)</label>
                        <input class="form-control" id="lead" name="lead" x-model="lead" placeholder="z. B. 30 min, 1:15, 2 h">
                    </div>
                    <div class="col-6">
                        <label class="form-label small" for="trail"><i class="bi bi-box-arrow-right"></i> Nachlauf (Abreise)</label>
                        <input class="form-control" id="trail" name="trail" x-model="trail" placeholder="z. B. 45 min, 1 Tag">
                    </div>
                </div>
                <div class="form-text mb-3">Vor- und Nachlauf gehören zum Termin und erscheinen im Kalender schraffiert davor bzw. danach.</div>

                <div class="mb-3">
                    <label class="form-label" for="location">Ort</label>
                    <input class="form-control" id="location" name="location" x-model="location" maxlength="190">
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-sm-4">
                        <label class="form-label small" for="recurrence">Wiederholen</label>
                        <select class="form-select" id="recurrence" name="recurrence" x-model="recurrence">
                            <?php foreach (CalendarService::RECURRENCE as $k => $label): ?>
                                <option value="<?= e($k) ?>" <?= selected($k, $event['recurrence']) ?>><?= e($k === 'none' ? 'nicht wiederholen' : $label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-4 col-sm-3" x-show="recurrence !== 'none'" x-cloak>
                        <label class="form-label small" for="recurrence_interval">alle … mal</label>
                        <input type="number" min="1" max="99" class="form-control" id="recurrence_interval" name="recurrence_interval" value="<?= (int) ($event['recurrence_interval'] ?? 1) ?>">
                    </div>
                    <div class="col-8 col-sm-5" x-show="recurrence !== 'none'" x-cloak>
                        <label class="form-label small" for="recurrence_until">bis (optional)</label>
                        <input type="date" class="form-control" id="recurrence_until" name="recurrence_until" value="<?= e($event['recurrence_until'] ?? '') ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label d-block">Teilnehmer</label>
                    <?php foreach ($members as $m): $mid = (int) $m['id']; ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input" type="checkbox" id="p<?= $mid ?>" name="participants[]" value="<?= $mid ?>"
                                <?= checked($mid === $ownerId || in_array($mid, array_map('intval', $participants), true)) ?> <?= $mid === $ownerId ? 'disabled' : '' ?>>
                            <label class="form-check-label" for="p<?= $mid ?>"><?= e($m['name']) ?><?= $mid === $ownerId ? ' (Ersteller)' : '' ?></label>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Der Termin erscheint im Kalender aller Teilnehmer – dort als „Teilnehmer“ gekennzeichnet. Jeder plant sein eigenes Budget.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="note">Notiz</label>
                    <textarea class="form-control" id="note" name="note" rows="2"><?= e($event['note'] ?? '') ?></textarea>
                </div>

                <?php if ($showBudget): ?>
                    <fieldset class="border rounded p-2 mb-2">
                        <legend class="float-none w-auto px-1 fs-6 mb-0"><i class="bi bi-wallet2"></i> Mein Budget<span x-show="recurrence !== 'none'"> (je Termin)</span></legend>
                        <?= View::partial('calendar/budget_fields', ['budget' => $budget, 'accounts' => $accounts, 'categories' => $categories, 'defaultAccount' => $defaultAccount]) ?>
                        <div class="form-text">Optional. Fließt mit Konto in die Prognose ein; gebucht wird erst über „Als Ausgabe buchen“ am Termin.</div>
                    </fieldset>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-grid d-sm-flex gap-2">
                <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> Speichern</button>
                <a class="btn btn-outline-secondary" href="<?= e(url($back)) ?>">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
