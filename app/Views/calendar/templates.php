<?php
use App\Core\View;
use App\Services\CalendarService;

$isEdit = !empty($tpl['id']);
$dur = (int) $tpl['duration_minutes'];
?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header bg-transparent"><strong>Meine Vorlagen</strong></div>
            <?php if (!$templates): ?>
                <div class="card-body small text-body-secondary">Noch keine Vorlagen. Eine Vorlage speichert Dauer, Vorlauf (Anreise) und Nachlauf (Abreise)
                    einer Terminart – z. B. „Schwimmtraining: 1:30, 20 Min. Anfahrt, 30 Min. Heimweg“.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($templates as $t): ?>
                        <li class="list-group-item d-flex gap-2 align-items-center">
                            <span class="rounded" style="width: .6rem; align-self: stretch; background: <?= e($t['color']) ?>"></span>
                            <div class="flex-grow-1 small">
                                <div class="fw-semibold"><?= e($t['name']) ?></div>
                                <div class="text-body-secondary">
                                    <?= $t['all_day'] ? e(CalendarService::formatDuration((int) $t['duration_minutes'])) . ' ganztägig' : e(CalendarService::formatDuration((int) $t['duration_minutes'])) ?>
                                    <?= (int) $t['lead_minutes'] ? ' · <i class="bi bi-box-arrow-in-right"></i> ' . e(CalendarService::formatDuration((int) $t['lead_minutes'])) : '' ?>
                                    <?= (int) $t['trail_minutes'] ? ' · <i class="bi bi-box-arrow-right"></i> ' . e(CalendarService::formatDuration((int) $t['trail_minutes'])) : '' ?>
                                    <?= $t['budget_amount'] !== null ? ' · <i class="bi bi-wallet2"></i> ' . money($t['budget_amount']) : '' ?>
                                </div>
                            </div>
                            <a class="btn btn-sm btn-primary" href="<?= e(url('/calendar/new', ['template' => $t['id']])) ?>" title="Termin aus Vorlage"><i class="bi bi-calendar-plus"></i></a>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/calendar/templates', ['edit' => $t['id']])) ?>" title="Bearbeiten"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="<?= e(url("/calendar/templates/{$t['id']}/delete")) ?>" onsubmit="return confirm('Vorlage löschen?')">
                                <?= csrf_field() ?>
                                <button class="btn btn-sm btn-outline-danger" title="Löschen"><i class="bi bi-trash"></i></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <form method="post" action="<?= e(url($isEdit ? "/calendar/templates/{$tpl['id']}" : '/calendar/templates')) ?>" class="card">
            <?= csrf_field() ?>
            <div class="card-header bg-transparent"><strong><?= $isEdit ? 'Vorlage bearbeiten' : 'Neue Vorlage' ?></strong></div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="name">Name der Vorlage</label>
                    <div class="input-group">
                        <input class="form-control" id="name" name="name" required maxlength="100" value="<?= e($tpl['name']) ?>" placeholder="z. B. Schwimmtraining, Wochenendausflug">
                        <input type="color" class="form-control form-control-color flex-grow-0" style="width: 3.5rem" name="color" value="<?= e($tpl['color']) ?>" title="Farbe">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-sm-6">
                        <label class="form-label small" for="title">Titel des Termins (optional)</label>
                        <input class="form-control" id="title" name="title" maxlength="190" value="<?= e($tpl['title'] ?? '') ?>" placeholder="sonst der Name">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small" for="location">Ort (optional)</label>
                        <input class="form-control" id="location" name="location" maxlength="190" value="<?= e($tpl['location'] ?? '') ?>">
                    </div>
                </div>
                <div class="row g-2 mb-1">
                    <div class="col-4">
                        <label class="form-label small" for="duration">Dauer</label>
                        <input class="form-control" id="duration" name="duration" required value="<?= e(CalendarService::formatDuration($dur)) ?>" placeholder="1:30">
                    </div>
                    <div class="col-4">
                        <label class="form-label small" for="lead"><i class="bi bi-box-arrow-in-right"></i> Vorlauf</label>
                        <input class="form-control" id="lead" name="lead" value="<?= e(CalendarService::formatDuration((int) $tpl['lead_minutes'])) ?>" placeholder="30 min">
                    </div>
                    <div class="col-4">
                        <label class="form-label small" for="trail"><i class="bi bi-box-arrow-right"></i> Nachlauf</label>
                        <input class="form-control" id="trail" name="trail" value="<?= e(CalendarService::formatDuration((int) $tpl['trail_minutes'])) ?>" placeholder="30 min">
                    </div>
                </div>
                <div class="form-text mb-2">Eingabe z. B. „90“ (Minuten), „1:30“, „2 h“, „45 min“, „1 Tag“, „2 Tage 3 h“.</div>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" role="switch" id="all_day" name="all_day" value="1" <?= checked($tpl['all_day']) ?>>
                    <label class="form-check-label" for="all_day">Ganztägig (Dauer in Tagen)</label>
                </div>
                <fieldset class="border rounded p-2">
                    <legend class="float-none w-auto px-1 fs-6 mb-0"><i class="bi bi-wallet2"></i> Budget-Vorschlag (optional)</legend>
                    <div class="row g-2">
                        <div class="col-sm-4">
                            <label class="form-label small" for="budget_amount">Betrag</label>
                            <input class="form-control" id="budget_amount" name="budget_amount" inputmode="decimal" placeholder="0,00"
                                   value="<?= e($tpl['budget_amount'] !== null ? money_input($tpl['budget_amount']) : '') ?>">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small" for="budget_account_id">Konto</label>
                            <select class="form-select" id="budget_account_id" name="budget_account_id">
                                <option value="">–</option>
                                <?php foreach ($accounts as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $tpl['budget_account_id']) ?>><?= e($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label small" for="budget_category_id">Kategorie</label>
                            <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'budget_category_id', 'selected' => $tpl['budget_category_id'], 'id' => 'budget_category_id', 'emptyLabel' => '–']) ?>
                        </div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg"></i> Speichern</button>
                <?php if ($isEdit): ?><a class="btn btn-outline-secondary" href="<?= e(url('/calendar/templates')) ?>">Abbrechen</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>
