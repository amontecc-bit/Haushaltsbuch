<?php $query = array_filter($filters); ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Einkäufe <?= e(month_de((int) date('n'))) ?></div>
        <div class="stat-value"><?= (int) $month['cnt'] ?></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Summe <?= e(month_de((int) date('n'))) ?></div>
        <div class="stat-value"><?= money($month['total']) ?></div>
    </div></div></div>
    <div class="col-md-6 d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary w-100" href="<?= e(url('/shopping')) ?>"><i class="bi bi-card-checklist"></i> Einkaufsliste</a>
        <a class="btn btn-outline-secondary w-100" href="<?= e(url('/reports/items')) ?>"><i class="bi bi-bar-chart"></i> Einzelposten</a>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-2 d-flex gap-2 flex-wrap">
        <input class="form-control flex-grow-1" style="min-width: 12rem" name="q" value="<?= e($filters['q']) ?>" placeholder="Geschäft oder Artikel suchen">
        <input type="date" class="form-control w-auto" name="from" value="<?= e($filters['from']) ?>" title="Von">
        <input type="date" class="form-control w-auto" name="to" value="<?= e($filters['to']) ?>" title="Bis">
        <button class="btn btn-primary"><i class="bi bi-search"></i></button>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="card empty-state">
        <i class="bi bi-receipt"></i>
        <p>Noch keine Einkäufe erfasst. Fotografiere einen Kassenbon – die Posten werden automatisch erkannt und Kategorien zugeordnet.</p>
        <a class="btn btn-primary" href="<?= e(url('/purchases/new', ['mode' => 'photo'])) ?>"><i class="bi bi-camera"></i> Ersten Bon fotografieren</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="list-group list-group-flush tx-list">
            <?php foreach ($rows as $p): ?>
                <a class="list-group-item list-group-item-action" href="<?= e(url("/purchases/{$p['id']}")) ?>">
                    <span class="cat-dot" style="background: #198754"><i class="bi bi-<?= $p['source'] === 'photo' ? 'camera' : ($p['source'] === 'pdf' ? 'file-earmark-pdf' : 'basket') ?>"></i></span>
                    <div class="tx-main">
                        <div class="tx-title"><?= e($p['store'] ?: 'Einkauf') ?></div>
                        <div class="tx-sub">
                            <?= e(date_de($p['purchase_date'])) ?> · <?= (int) $p['item_count'] ?> Posten
                            <?= $p['account_name'] ? ' · ' . e($p['account_name']) : '' ?>
                            <?= $p['transaction_id'] ? ' · <i class="bi bi-link-45deg" title="mit Buchung verknüpft"></i>' : '' ?>
                        </div>
                    </div>
                    <div class="amount text-danger"><?= money(-(float) $p['total']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <nav class="d-flex justify-content-between mt-3">
        <?php if ($page > 1): ?><a class="btn btn-outline-secondary" href="<?= e(url('/purchases', $query + ['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i> Neuer</a><?php else: ?><span></span><?php endif; ?>
        <?php if ($hasMore): ?><a class="btn btn-outline-secondary" href="<?= e(url('/purchases', $query + ['page' => $page + 1])) ?>">Älter <i class="bi bi-chevron-right"></i></a><?php endif; ?>
    </nav>
<?php endif; ?>
