<?php
use App\Core\View;

$query = array_filter($filters, fn ($v) => $v !== null && $v !== '');
$hasFilter = (bool) array_diff_key($query, ['account_id' => 1]);
?>
<div x-data="txList()">
    <!-- Filter -->
    <form method="get" class="card mb-3">
        <div class="card-body py-2">
            <div class="d-flex gap-2 align-items-center">
                <div class="input-group">
                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                    <input class="form-control" name="q" value="<?= e($filters['q']) ?>" placeholder="Suchen (Empfänger, Zweck …)">
                </div>
                <button type="button" class="btn btn-outline-secondary position-relative" data-bs-toggle="collapse" data-bs-target="#filterMore" aria-label="Filter">
                    <i class="bi bi-funnel"></i>
                    <?php if ($hasFilter): ?><span class="position-absolute top-0 start-100 translate-middle p-1 bg-primary rounded-circle"></span><?php endif; ?>
                </button>
                <button class="btn btn-primary d-none d-md-inline-block">Suchen</button>
            </div>
            <div class="collapse <?= $hasFilter ? 'show' : '' ?>" id="filterMore">
                <div class="row g-2 mt-1">
                    <div class="col-6 col-md-3">
                        <select name="account_id" class="form-select form-select-sm">
                            <option value="">Alle Konten</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $filters['account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">Alle Kategorien</option>
                            <option value="none" <?= selected('none', $filters['category_id']) ?>>⚠ Ohne Kategorie</option>
                            <?php foreach ($categories as $c): ?>
                                <option value="<?= (int) $c['id'] ?>" <?= selected($c['id'], $filters['category_id']) ?>><?= $c['parent_id'] ? '&nbsp;&nbsp;' : '' ?><?= e($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">Alle Arten</option>
                            <option value="expense" <?= selected('expense', $filters['type']) ?>>Ausgaben</option>
                            <option value="income" <?= selected('income', $filters['type']) ?>>Einnahmen</option>
                            <option value="transfer" <?= selected('transfer', $filters['type']) ?>>Umbuchungen</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-2">
                        <select name="user_id" class="form-select form-select-sm">
                            <option value="">Alle Personen</option>
                            <?php foreach ($users as $u): ?>
                                <option value="<?= (int) $u['id'] ?>" <?= selected($u['id'], $filters['user_id']) ?>><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-1"><input type="date" name="from" class="form-control form-control-sm" value="<?= e($filters['from']) ?>" title="Von"></div>
                    <div class="col-6 col-md-1"><input type="date" name="to" class="form-control form-control-sm" value="<?= e($filters['to']) ?>" title="Bis"></div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-sm btn-primary">Filtern</button>
                        <a href="<?= e(url('/transactions')) ?>" class="btn btn-sm btn-outline-secondary">Zurücksetzen</a>
                    </div>
                </div>
            </div>
        </div>
    </form>

    <!-- Summen -->
    <div class="d-flex gap-2 mb-3 flex-wrap small">
        <span class="badge rounded-pill text-bg-light border"><?= $summary['count'] ?> Buchungen</span>
        <span class="badge rounded-pill text-bg-success-subtle text-success-emphasis border">Einnahmen <?= money($summary['income']) ?></span>
        <span class="badge rounded-pill text-bg-danger-subtle text-danger-emphasis border">Ausgaben <?= money($summary['expense']) ?></span>
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
                                    <?php if ($isTransfer): ?>
                                        <?= (float) $t['amount'] < 0 ? 'an ' : 'von ' ?><?= e($t['transfer_account_name'] ?? '?') ?>
                                    <?php elseif ($t['category_name']): ?>
                                        <?= e($t['category_parent'] ? $t['category_parent'] . ' › ' : '') . e($t['category_name']) ?>
                                    <?php else: ?>
                                        <span class="text-warning-emphasis">ohne Kategorie</span>
                                    <?php endif; ?>
                                    · <span style="color: <?= e($t['account_color']) ?>">●</span> <?= e($t['account_name']) ?>
                                    <?php if ($t['source'] === 'recurring'): ?> · <i class="bi bi-arrow-repeat" title="Dauerauftrag"></i><?php endif; ?>
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
            <div class="sticky-actions d-flex gap-2 align-items-center mt-2" x-show="selected.length" x-cloak>
                <span class="small text-nowrap"><span x-text="selected.length"></span> ausgewählt</span>
                <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'class' => 'form-select form-select-sm', 'emptyLabel' => 'Kategorie wählen …']) ?>
                <button class="btn btn-sm btn-primary text-nowrap" name="action" value="categorize">Zuordnen</button>
                <button class="btn btn-sm btn-outline-danger" name="action" value="delete" onclick="return confirm('Ausgewählte Buchungen löschen?')" aria-label="Löschen"><i class="bi bi-trash"></i></button>
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
    function txList() {
        return {
            selected: [],
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
        };
    }
</script>
