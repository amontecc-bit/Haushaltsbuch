<?php
use App\Core\View;

ob_start(); ?>
<select name="category_id" class="form-select w-auto">
    <option value="">Alle Kategorien</option>
    <option value="0" <?= selected(0, $categoryId) ?>>Ohne Kategorie</option>
    <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= selected($c['id'], $categoryId) ?>><?= $c['parent_id'] ? '&nbsp;&nbsp;' : '' ?><?= e($c['name']) ?></option><?php endforeach; ?>
</select>
<input class="form-control w-auto" name="q" value="<?= e($q) ?>" placeholder="Artikel suchen">
<select name="sort" class="form-select w-auto">
    <?php foreach (['total' => 'nach Betrag', 'count' => 'nach Häufigkeit', 'price' => 'nach Stückpreis', 'name' => 'nach Name'] as $k => $l): ?>
        <option value="<?= $k ?>" <?= selected($k, $sort) ?>><?= e($l) ?></option>
    <?php endforeach; ?>
</select>
<?php $extra = ob_get_clean(); ?>
<?= View::partial('partials/period_filter', compact('period', 'from', 'to', 'extra') + ['action' => '/reports/items']) ?>

<?php
// Links der Breadcrumb-Zeile behalten Zeitraum, Suche und Sortierung
$base = ['period' => $period, 'from' => $from, 'to' => $to, 'q' => $q !== '' ? $q : null, 'sort' => $sort !== 'total' ? $sort : null];
$catUrl = fn (?int $id) => url('/reports/items', $base + ['category_id' => $id]);
$upUrl = $categoryId === null ? null : $catUrl($parentCat ? (int) $parentCat['id'] : null);
?>
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-2">
            <?php if ($upUrl): ?>
                <a class="btn btn-sm btn-outline-secondary" href="<?= e($upUrl) ?>" title="Eine Ebene höher" aria-label="Eine Ebene höher"><i class="bi bi-arrow-left"></i></a>
            <?php endif; ?>
            <nav aria-label="Kategorie-Pfad" class="flex-grow-1" style="min-width: 0">
                <ol class="breadcrumb mb-0">
                    <?php if ($categoryId === null): ?>
                        <li class="breadcrumb-item active fw-semibold" aria-current="page">Alle Kategorien</li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="<?= e($catUrl(null)) ?>">Alle Kategorien</a></li>
                        <?php if ($parentCat): ?>
                            <li class="breadcrumb-item"><a href="<?= e($catUrl((int) $parentCat['id'])) ?>"><span style="color: <?= e($parentCat['color']) ?>">●</span> <?= e($parentCat['name']) ?></a></li>
                        <?php endif; ?>
                        <li class="breadcrumb-item active fw-semibold" aria-current="page">
                            <?php if ($current): ?><span style="color: <?= e($current['color']) ?>">●</span> <?= e($current['name']) ?><?php else: ?>Ohne Kategorie<?php endif; ?>
                        </li>
                    <?php endif; ?>
                </ol>
            </nav>
            <div class="text-end text-nowrap lh-sm">
                <strong class="amount"><?= money($summary['total']) ?></strong>
                <div class="small text-body-secondary"><?= (int) $summary['count'] ?> Posten<?= $q !== '' ? ' · Suche' : '' ?></div>
            </div>
        </div>
        <?php if ($children): ?>
            <div class="mt-2">
                <?php foreach ($children as $ch): ?>
                    <a class="sub-choice<?= $ch['total'] > 0 ? '' : ' opacity-50' ?>" href="<?= e($catUrl((int) $ch['id'])) ?>"><?= e($ch['name']) ?><?php if ($ch['total'] > 0): ?> <span class="small"><?= money($ch['total']) ?></span><?php endif; ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$top = array_slice($byCategory, 0, 7);
$rest = array_slice($byCategory, 7);
if ($rest) {
    $top[] = ['name' => 'Übrige', 'total' => array_sum(array_column($rest, 'total')), 'color' => null];
}
?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header bg-transparent"><strong>Einkäufe nach Kategorie</strong></div>
            <div class="card-body">
                <?php if (!$byCategory): ?>
                    <div class="small text-body-secondary">Keine Einkäufe im Zeitraum.</div>
                <?php else: ?>
                    <div class="chart-box" style="height: 220px"><canvas id="itemCats" role="img" aria-label="Einkaufsposten nach Kategorie"></canvas></div>
                    <?php foreach ($byCategory as $c): ?>
                        <div class="d-flex justify-content-between small mt-1">
                            <a class="text-body" href="<?= e(url('/reports/items', ['period' => $period, 'from' => $from, 'to' => $to, 'category_id' => $c['id']])) ?>"><span style="color: <?= e($c['color']) ?>">●</span> <?= e($c['name']) ?></a>
                            <span class="amount"><?= money($c['total']) ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Artikel</th><th class="text-end">Anzahl</th><th class="text-end d-none d-md-table-cell">Ø Preis</th><th class="text-end d-none d-md-table-cell">Min – Max</th><th class="text-end">Summe</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php $mixed = (int) $r['category_count'] > 1; ?>
                        <tr x-data="{ edit: false }">
                            <td>
                                <?php if ($r['product_id']): ?>
                                    <a href="<?= e(url('/reports/product', ['id' => $r['product_id']])) ?>"><?= e($r['name']) ?></a>
                                <?php else: ?><?= e($r['name']) ?><?php endif; ?>
                                <div class="small text-body-secondary">
                                    <button type="button" class="btn btn-link btn-sm p-0 align-baseline text-body-secondary text-decoration-none" @click="edit = !edit" title="Kategorie korrigieren">
                                        <?php if ($mixed): ?>mehrere Kategorien
                                        <?php elseif ($r['category_name']): ?><span style="color: <?= e($r['category_color']) ?>">●</span> <?= e($r['category_name']) ?>
                                        <?php else: ?>ohne Kategorie<?php endif; ?>
                                        <i class="bi bi-pencil small"></i>
                                    </button>
                                    · zuletzt <?= e(date_de($r['last_date'])) ?><?= $r['stores'] ? ' · ' . e(mb_strimwidth($r['stores'], 0, 40, '…')) : '' ?>
                                </div>
                                <form method="post" action="<?= e(url('/reports/items/category')) ?>" class="mt-2" x-show="edit" x-cloak style="display: none">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="product_id" value="<?= (int) $r['product_id'] ?>">
                                    <input type="hidden" name="name" value="<?= e($r['item_name']) ?>">
                                    <div class="d-flex gap-2">
                                        <?= View::partial('partials/category_select', [
                                            'categories' => $categories, 'name' => 'category_id', 'type' => 'expense',
                                            'selected' => $mixed ? null : $r['category_id'], 'class' => 'form-select form-select-sm',
                                        ]) ?>
                                        <button class="btn btn-sm btn-primary text-nowrap"><i class="bi bi-check-lg"></i> Speichern</button>
                                    </div>
                                    <div class="form-text">Gilt für alle Käufe dieses Artikels<?= $r['product_id'] ? ' und künftige Vorschläge' : '' ?>.</div>
                                </form>
                            </td>
                            <td class="text-end"><?= (int) $r['cnt'] ?>×</td>
                            <td class="table-amount d-none d-md-table-cell"><?= money($r['avg_price']) ?></td>
                            <td class="table-amount d-none d-md-table-cell small text-body-secondary"><?= money($r['min_price']) ?> – <?= money($r['max_price']) ?></td>
                            <td class="table-amount"><?= money($r['total']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?><tr><td colspan="5" class="small text-body-secondary">Keine Posten gefunden.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($byCategory): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const d = <?= json_encode(array_map(fn ($c) => ['name' => $c['name'], 'total' => round($c['total'], 2), 'color' => $c['color']], $top)) ?>;
        new Chart(document.getElementById('itemCats'), {
            type: 'doughnut',
            data: { labels: d.map(x => x.name), datasets: [{ data: d.map(x => x.total), backgroundColor: d.map(x => x.color || HB.otherColor()) }] },
            options: { maintainAspectRatio: false, cutout: '62%', interaction: { mode: 'nearest', intersect: true }, plugins: { legend: { display: false } } },
        });
    });
</script>
<?php endif; ?>
