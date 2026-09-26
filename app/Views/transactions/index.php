<?php
use App\Controllers\Controller;
use App\Core\View;

// von/bis nur bei eigenem Zeitraum in Links übernehmen, sonst ergibt sich beides aus der Periode
$query = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
if ($filters['period'] !== 'custom') {
    unset($query['from'], $query['to']);
}
if ($filters['period'] === 'all') {
    unset($query['period']);
}
$hasFilter = (bool) $query;
$selectable = array_values(array_map(fn ($t) => (int) $t['id'], array_filter($rows, fn ($t) => in_array((int) $t['account_id'], $canBook, true))));
?>
<div x-data="txList(<?= e(json_encode($selectable)) ?>)">
    <!-- Filter: Änderungen werden sofort angewendet -->
    <form method="get" class="card mb-3" x-data="{ period: '<?= e($filters['period']) ?>' }">
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
                    <input class="form-control form-control-sm" name="q" value="<?= e($filters['q']) ?>" placeholder="Suchen (Empfänger, Zweck …)" onchange="this.form.submit()">
                </div>
                <div class="col-12 col-sm-6 col-md-4">
                    <select name="category_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Alle Kategorien</option>
                        <option value="none" <?= selected('none', $filters['category_id']) ?>>⚠ Ohne Kategorie</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= (int) $c['id'] ?>" <?= selected($c['id'], $filters['category_id']) ?>><?= $c['parent_id'] ? '&nbsp;&nbsp;' : '' ?><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Alle Arten</option>
                        <option value="expense" <?= selected('expense', $filters['type']) ?>>Ausgaben</option>
                        <option value="income" <?= selected('income', $filters['type']) ?>>Einnahmen</option>
                        <option value="transfer" <?= selected('transfer', $filters['type']) ?>>Umbuchungen</option>
                        <option value="fixed" <?= selected('fixed', $filters['type']) ?>>Fixkosten</option>
                    </select>
                </div>
                <div class="col-6 col-sm-3 col-md-2">
                    <select name="user_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Alle Personen</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int) $u['id'] ?>" <?= selected($u['id'], $filters['user_id']) ?>><?= e($u['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-sm-4 col-md-3">
                    <select name="period" class="form-select form-select-sm" x-model="period" @change="period !== 'custom' && $el.form.submit()" aria-label="Zeitraum">
                        <?php foreach (['all' => 'Gesamter Zeitraum'] + Controller::PERIODS as $k => $l): ?>
                            <option value="<?= $k ?>" <?= selected($k, $filters['period']) ?>><?= e($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <template x-if="period === 'custom'">
                    <div class="col-12 col-sm-8 col-md-5 d-flex gap-2">
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">von</span>
                            <input type="date" name="from" class="form-control" value="<?= e($filters['from']) ?>" onchange="this.form.submit()">
                        </div>
                        <div class="input-group input-group-sm">
                            <span class="input-group-text">bis</span>
                            <input type="date" name="to" class="form-control" value="<?= e($filters['to']) ?>" onchange="this.form.submit()">
                        </div>
                    </div>
                </template>
                <div class="col-12 col-md-2 d-grid">
                    <?php if ($hasFilter): ?>
                        <a href="<?= e(url('/transactions')) ?>" class="btn btn-sm btn-outline-secondary" title="Filter zurücksetzen"><i class="bi bi-x-lg"></i> Zurücksetzen</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($filters['account_ids']): ?>
                <div class="form-text">Umbuchungen zu bzw. von nicht gewählten Konten zählen als Ausgabe bzw. Einnahme.</div>
            <?php endif; ?>
        </div>
    </form>

    <!-- Summen -->
    <div class="d-flex gap-2 mb-3 flex-wrap small">
        <span class="badge rounded-pill text-bg-light border"><?= $summary['count'] ?> Buchungen</span>
        <span class="badge rounded-pill text-bg-success-subtle text-success-emphasis border">Einnahmen <?= money($summary['income']) ?></span>
        <span class="badge rounded-pill text-bg-danger-subtle text-danger-emphasis border">Ausgaben <?= money($summary['expense']) ?></span>
        <?php if ($filters['from']): ?>
            <span class="ms-auto text-body-secondary align-self-center"><i class="bi bi-calendar3"></i> <?= e(date_de($filters['from'])) ?> – <?= e(date_de($filters['to'])) ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="card empty-state">
            <i class="bi bi-inbox"></i>
            <p>Keine Buchungen gefunden.</p>
            <div class="d-flex gap-2 justify-content-center">
                <a class="btn btn-primary" href="<?= e(url('/transactions/new')) ?>">Buchung erfassen</a>
                <a class="btn btn-outline-secondary" href="<?= e(url('/import')) ?>">Kontoauszug importieren</a>
            </div>
        </div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/transactions/bulk')) ?>" id="bulkForm">
            <?= csrf_field() ?>
            <div class="card">
                <div class="list-group list-group-flush tx-list">
                    <?php if ($selectable): ?>
                        <label class="list-group-item small text-body-secondary d-flex align-items-center gap-2">
                            <input type="checkbox" class="form-check-input m-0" :checked="allSelected()" :indeterminate="someSelected()" @change="toggleAll()">
                            Alle auf dieser Seite auswählen (<?= count($selectable) ?>)
                        </label>
                    <?php endif; ?>
                    <?php $lastDate = null; foreach ($rows as $t): ?>
                        <?php if ($t['booking_date'] !== $lastDate): $lastDate = $t['booking_date']; ?>
                            <div class="date-sep"><?= e(date_de($t['booking_date'])) ?></div>
                        <?php endif; ?>
                        <?php
                        $isTransfer = (bool) $t['transfer_group'];
                        $title = $t['payee'] ?: ($t['purpose'] ? mb_strimwidth($t['purpose'], 0, 60, '…') : ($isTransfer ? 'Umbuchung' : 'Buchung'));
                        $canEdit = in_array((int) $t['account_id'], $canBook, true);
                        ?>
                        <div class="list-group-item list-group-item-action" id="tx-<?= (int) $t['id'] ?>">
                            <?php if ($canEdit): ?>
                                <input type="checkbox" class="form-check-input flex-shrink-0" name="ids[]" value="<?= (int) $t['id'] ?>" x-model="selected" aria-label="Auswählen">
                            <?php endif; ?>
                            <?php if ($isTransfer): ?>
                                <?= View::partial('partials/category_badge', ['icon' => 'arrow-left-right', 'color' => '#0d6efd']) ?>
                            <?php else: ?>
                                <?= View::partial('partials/category_badge', ['icon' => $t['category_icon'], 'color' => $t['category_color']]) ?>
                            <?php endif; ?>
                            <a class="tx-main text-decoration-none text-body" href="<?= e(url("/transactions/{$t['id']}/edit")) ?>">
                                <div class="tx-title"><?= e($title) ?></div>
                                <div class="tx-sub">
                                    <?php if ($t['recurring_id']): ?><span class="text-primary-emphasis" title="Fixkosten: <?= e(($t['recurring_payee'] ?: 'wiederkehrende Buchung') . ' (' . interval_label((string) $t['recurring_interval']) . ')') ?>"><i class="bi bi-arrow-repeat"></i> Fixkosten</span> · <?php endif; ?>
                                    <?php if ($isTransfer): ?>
                                        <?= (float) $t['amount'] < 0 ? 'an ' : 'von ' ?><?= e($t['transfer_account_name'] ?? '?') ?>
                                    <?php elseif ($t['category_name']): ?>
                                        <?= e($t['category_parent'] ? $t['category_parent'] . ' › ' : '') . e($t['category_name']) ?>
                                    <?php else: ?>
                                        <span class="text-warning-emphasis">ohne Kategorie</span>
                                    <?php endif; ?>
                                    · <span style="color: <?= e($t['account_color']) ?>">●</span> <?= e($t['account_name']) ?>
                                    <?php if ($t['purchase_id']): ?> · <i class="bi bi-receipt" title="Mit Einkauf verknüpft"></i><?php endif; ?>
                                </div>
                            </a>
                            <?php if (!$isTransfer && !$t['category_name'] && $canEdit): ?>
                                <button type="button" class="btn btn-sm btn-outline-warning d-none d-sm-inline-block" @click="pick(<?= (int) $t['id'] ?>, <?= (float) $t['amount'] < 0 ? "'expense'" : "'income'" ?>)">Kategorie</button>
                            <?php endif; ?>
                            <div class="amount <?= $isTransfer ? 'text-primary' : money_class($t['amount']) ?>"><?= money($t['amount']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Aktionen für ausgewählte Buchungen -->
            <div class="sticky-actions d-flex gap-2 align-items-center mt-2 flex-wrap" x-show="selected.length" x-cloak>
                <span class="small text-nowrap"><span x-text="selected.length"></span> ausgewählt</span>
                <select name="action" class="form-select form-select-sm w-auto" x-model="action" aria-label="Aktion">
                    <option value="categorize">Kategorie zuordnen</option>
                    <option value="move">Anderem Konto zuweisen</option>
                    <option value="recurring">Als Fixkosten übernehmen</option>
                    <option value="delete">Löschen</option>
                </select>
                <div class="w-auto" x-show="action === 'categorize'">
                    <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'class' => 'form-select form-select-sm', 'emptyLabel' => 'Kategorie wählen …', 'attrs' => ':disabled="action !== \'categorize\'"']) ?>
                </div>
                <select name="account_id" class="form-select form-select-sm w-auto" x-show="action === 'move'" :disabled="action !== 'move'" aria-label="Konto">
                    <?php foreach ($accounts as $a): if (!in_array((int) $a['id'], $canBook, true)) { continue; } ?>
                        <option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <template x-if="action === 'recurring'">
                    <div class="d-flex gap-2 align-items-center">
                        <select name="interval" class="form-select form-select-sm w-auto" aria-label="Intervall">
                            <?php foreach (['monthly', 'quarterly', 'halfyearly', 'yearly'] as $i): ?>
                                <option value="<?= $i ?>"><?= e(interval_label($i)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch mb-0 text-nowrap" title="Aus = nur Prognose (z. B. wenn die Buchungen per CSV-Import kommen)">
                            <input class="form-check-input" type="checkbox" role="switch" id="bulk_auto_book" name="auto_book" value="1">
                            <label class="form-check-label small" for="bulk_auto_book">automatisch buchen</label>
                        </div>
                    </div>
                </template>
                <button class="btn btn-sm text-nowrap" :class="action === 'delete' ? 'btn-danger' : 'btn-primary'"
                        @click="if (action === 'delete' && !confirm('Ausgewählte Buchungen löschen?')) $event.preventDefault()">Ausführen</button>
            </div>
            <div class="small text-body-secondary mt-1" x-show="selected.length && action === 'recurring'" x-cloak>
                Gleiche Zahlungen (Konto, Empfänger, Betrag) werden zu einem Fixkosten-Eintrag zusammengefasst.
            </div>
        </form>

        <nav class="d-flex justify-content-between mt-3">
            <?php if ($page > 1): ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('/transactions', $query + ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i> Neuer</a>
            <?php else: ?><span></span><?php endif; ?>
            <?php if ($hasMore): ?>
                <a class="btn btn-outline-secondary" href="<?= e(url('/transactions', $query + ['page' => $page + 1])) ?>">Älter <i class="bi bi-chevron-right"></i></a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>

    <!-- Dialog: Kategorie schnell zuordnen -->
    <div class="modal fade" id="pickModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header"><h5 class="modal-title">Kategorie zuordnen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'pick_category', 'id' => 'pickSelect', 'class' => 'form-select form-select-lg']) ?>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-primary" @click="savePick()">Speichern</button></div>
            </div>
        </div>
    </div>
</div>

<script>
    function txList(ids) {
        return Object.assign(HB.selection(ids), {
            action: 'categorize',
            pickId: null,
            modal: null,
            pick(id) {
                this.pickId = id;
                this.modal = this.modal || new bootstrap.Modal(document.getElementById('pickModal'));
                this.modal.show();
            },
            async savePick() {
                const cat = document.getElementById('pickSelect').value;
                try {
                    await HB.post('/transactions/' + this.pickId + '/category', { category_id: cat });
                    this.modal.hide();
                    location.reload();
                } catch (e) { alert(e.message); }
            },
        });
    }
</script>
