<?php
use App\Core\View;

$hasFilter = $filters['account_ids'] || $filters['category_id'] || $filters['type'] || $filters['status'] || $filters['q'] !== '';
$selectable = array_values(array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => in_array((int) $r['account_id'], $canBook, true))));
?>
<div class="row g-3 mb-3">
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Fixe Einnahmen / Monat</div>
        <div class="stat-value text-success"><?= money($income, true) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Fixkosten / Monat</div>
        <div class="stat-value text-danger"><?= money(abs($expense), true) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Frei verfügbar</div>
        <div class="stat-value <?= money_class($income + $expense) ?>"><?= money($income + $expense, true) ?></div>
    </div></div></div>
</div>

<!-- Filter: Änderungen werden sofort angewendet -->
<form method="get" class="card mb-3" id="recFilter">
    <div class="card-body py-2">
        <?php if (count($accounts) > 1): ?>
            <div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Konten">
                <?php foreach ($accounts as $a): $aid = (int) $a['id']; ?>
                    <input type="checkbox" class="btn-check" name="account_ids[]" value="<?= $aid ?>" id="fa-<?= $aid ?>" autocomplete="off"
                           <?= in_array($aid, $filters['account_ids'], true) ? 'checked' : '' ?> onchange="this.form.submit()">
                    <label class="btn btn-sm btn-outline-secondary" for="fa-<?= $aid ?>"><span style="color: <?= e($a['color'] ?? '#6c757d') ?>">●</span> <?= e($a['name']) ?></label>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <div class="row g-2">
            <div class="col-12 col-md-4">
                <input class="form-control form-control-sm" name="q" value="<?= e($filters['q']) ?>" placeholder="Suchen (Bezeichnung, Zweck …)" onchange="this.form.submit()">
            </div>
            <div class="col-12 col-sm-4 col-md-3">
                <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Alle Kategorien</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= selected($c['id'], $filters['category_id']) ?>><?= $c['parent_id'] ? '&nbsp;&nbsp;' : '' ?><?= e($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Alle Arten</option>
                    <option value="expense" <?= selected('expense', $filters['type']) ?>>Ausgaben</option>
                    <option value="income" <?= selected('income', $filters['type']) ?>>Einnahmen</option>
                    <option value="transfer" <?= selected('transfer', $filters['type']) ?>>Umbuchungen</option>
                </select>
            </div>
            <div class="col-6 col-sm-4 col-md-2">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Alle Status</option>
                    <option value="active" <?= selected('active', $filters['status']) ?>>Aktiv</option>
                    <option value="auto" <?= selected('auto', $filters['status']) ?>>Automatisch gebucht</option>
                    <option value="forecast" <?= selected('forecast', $filters['status']) ?>>Nur Prognose</option>
                    <option value="paused" <?= selected('paused', $filters['status']) ?>>Pausiert</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-grid">
                <?php if ($hasFilter): ?>
                    <a href="<?= e(url('/recurring')) ?>" class="btn btn-sm btn-outline-secondary" title="Filter zurücksetzen"><i class="bi bi-x-lg"></i><span class="d-md-none"> Zurücksetzen</span></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>
<p class="small text-body-secondary">
    Quartals-, Halbjahres- und Jahresbeträge sind anteilig auf den Monat umgerechnet. Fällige Termine werden automatisch gebucht.
    <?php if ($filters['account_ids']): ?>Umbuchungen zu bzw. von nicht gewählten Konten zählen als Ausgabe bzw. Einnahme.<?php endif; ?>
</p>

<?php if (!$rows && !$hasFilter): ?>
    <div class="card empty-state">
        <i class="bi bi-arrow-repeat"></i>
        <p>Noch keine wiederkehrenden Buchungen. Lege Miete, Versicherungen, Abos, Gehalt usw. an – sie fließen in die Prognose ein.</p>
        <a href="<?= e(url('/recurring/new')) ?>" class="btn btn-primary">Anlegen</a>
    </div>
<?php elseif (!$rows): ?>
    <div class="card empty-state">
        <i class="bi bi-funnel"></i>
        <p>Keine Einträge für diesen Filter.</p>
        <a href="<?= e(url('/recurring')) ?>" class="btn btn-outline-secondary">Filter zurücksetzen</a>
    </div>
<?php else: ?>
    <form method="post" action="<?= e(url('/recurring/bulk')) ?>" x-data="Object.assign(HB.selection(<?= e(json_encode($selectable)) ?>), { action: 'forecast' })">
        <?= csrf_field() ?>
        <div class="card">
            <div class="list-group list-group-flush tx-list">
                <?php if ($selectable): ?>
                    <label class="list-group-item small text-body-secondary d-flex align-items-center gap-2">
                        <input type="checkbox" class="form-check-input m-0" :checked="allSelected()" :indeterminate="someSelected()" @change="toggleAll()">
                        Alle auswählen (<?= count($selectable) ?>)
                    </label>
                <?php endif; ?>
                <?php foreach ($rows as $r): ?>
                    <?php $canEdit = in_array((int) $r['account_id'], $canBook, true); ?>
                    <div class="list-group-item list-group-item-action <?= $r['active'] ? '' : 'opacity-50' ?>">
                        <?php if ($canEdit): ?>
                            <input type="checkbox" class="form-check-input flex-shrink-0" name="ids[]" value="<?= (int) $r['id'] ?>" x-model="selected" aria-label="Auswählen">
                        <?php endif; ?>
                        <?= View::partial('partials/category_badge', $r['to_account_id'] ? ['icon' => 'arrow-left-right', 'color' => '#0d6efd'] : ['icon' => $r['category_icon'], 'color' => $r['category_color']]) ?>
                        <a class="tx-main text-decoration-none text-body" href="<?= e(url("/recurring/{$r['id']}/edit")) ?>">
                            <div class="tx-title"><?= e($r['payee'] ?: ($r['category_name'] ?? ($r['to_account_id'] ? 'Umbuchung' : 'Dauerauftrag'))) ?></div>
                            <div class="tx-sub">
                                <?= e(interval_label($r['interval'])) ?> · <span style="color: <?= e($r['account_color']) ?>">●</span> <?= e($r['account_name']) ?><?= $r['to_account_id'] ? ' → ' . e($r['to_account_name']) : '' ?>
                                <?php if (!$r['active']): ?> · pausiert
                                <?php elseif ($r['next']): ?> · nächste: <?= e(date_de($r['next'])) ?>
                                <?php else: ?> · beendet<?php endif; ?>
                                <?php if (!$r['auto_book']): ?> · <span class="badge text-bg-light border" title="Wird nicht automatisch gebucht, nur in der Prognose berücksichtigt (z. B. weil die Buchung per CSV-Import kommt)">nur Prognose</span><?php endif; ?>
                                <?php if ($r['counterpart_account_name']): ?> · <span title="Gegeneintrag auf anderem Konto"><i class="bi bi-arrow-left-right"></i> <?= e($r['counterpart_account_name']) ?></span><?php endif; ?>
                                <?php if ($r['loan_id']): ?> · <i class="bi bi-bank"></i> Kredit<?php endif; ?>
                            </div>
                        </a>
                        <div class="amount <?= $r['to_account_id'] ? 'text-primary' : money_class($r['amount']) ?>"><?= money($r['amount']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Aktionen für ausgewählte Einträge -->
        <div class="sticky-actions d-flex gap-2 align-items-center mt-2 flex-wrap" x-show="selected.length" x-cloak>
            <span class="small text-nowrap"><span x-text="selected.length"></span> ausgewählt</span>
            <select name="action" class="form-select form-select-sm w-auto" x-model="action">
                <option value="forecast">Nur Prognose (nicht automatisch buchen)</option>
                <option value="auto">Automatisch buchen</option>
                <option value="pause">Pausieren</option>
                <option value="activate">Aktivieren</option>
                <option value="move">Anderem Konto zuweisen …</option>
                <option value="delete">Löschen</option>
            </select>
            <select name="account_id" class="form-select form-select-sm w-auto" x-show="action === 'move'" :disabled="action !== 'move'">
                <?php foreach ($accounts as $a): if (!in_array((int) $a['id'], $canBook, true)) { continue; } ?>
                    <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary text-nowrap" @click="if (action === 'delete' && !confirm('Ausgewählte Einträge löschen? Bereits gebuchte Einträge bleiben erhalten.')) $event.preventDefault()">Ausführen</button>
        </div>
    </form>
<?php endif; ?>
