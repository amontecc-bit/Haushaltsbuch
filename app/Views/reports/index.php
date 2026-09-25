<?php
use App\Core\View;

$saldo = $totals['income'] + $totals['expense'];
$rate = $totals['income'] > 0 ? $saldo / $totals['income'] * 100 : null;
$expTotal = array_sum(array_column($expenses, 'total')) ?: 1;
$incTotal = array_sum(array_column($incomes, 'total')) ?: 1;
$base = ['period' => $period, 'from' => $from, 'to' => $to, 'account_id' => $accountId, 'user_id' => $userId, 'split' => $split ? 1 : 0];
$base = array_filter($base, fn ($v) => $v !== null && $v !== '');

// Donut: bis zu 7 Kategorien + „Übrige“ (nie mehr als 8 Farben)
$donut = array_slice($expenses, 0, 7);
$rest = array_slice($expenses, 7);
if ($rest) {
    $donut[] = ['name' => 'Übrige', 'total' => array_sum(array_column($rest, 'total')), 'color' => null];
}

ob_start(); ?>
<select name="account_id" class="form-select w-auto">
    <option value="">Alle Konten</option>
    <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $accountId) ?>><?= e($a['name']) ?></option><?php endforeach; ?>
</select>
<select name="user_id" class="form-select w-auto">
    <option value="">Alle Personen</option>
    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= selected($u['id'], $userId) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
</select>
<input type="hidden" name="split" value="0">
<div class="form-check form-switch mb-0" title="Mit Einkäufen verknüpfte Buchungen nach den Kategorien der Posten aufteilen">
    <input class="form-check-input" type="checkbox" role="switch" id="split" name="split" value="1" <?= checked($split) ?>>
    <label class="form-check-label small" for="split">Einkäufe aufschlüsseln</label>
</div>
<?php if ($parentCat): ?><input type="hidden" name="parent" value="<?= (int) $parentCat['id'] ?>"><?php endif; ?>
<?php $extra = ob_get_clean(); ?>

<?= View::partial('partials/period_filter', compact('period', 'from', 'to', 'extra') + ['action' => '/reports']) ?>

<div class="d-flex gap-2 mb-3 flex-wrap">
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reports/items', ['period' => $period, 'from' => $from, 'to' => $to])) ?>"><i class="bi bi-receipt"></i> Einzelposten</a>
    <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('/reports/export', $base)) ?>"><i class="bi bi-download"></i> Buchungen als CSV</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Einnahmen</div><div class="stat-value"><?= money($totals['income']) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Ausgaben</div><div class="stat-value"><?= money(abs($totals['expense'])) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Saldo</div><div class="stat-value <?= money_class($saldo) ?>"><?= money($saldo) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Sparquote</div><div class="stat-value"><?= $rate === null ? '–' : e(number_format($rate, 1, ',', '.')) . ' %' ?></div>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-transparent">
                <strong>Ausgaben nach Kategorie</strong>
                <?php if ($parentCat): ?>
                    <div class="small"><a href="<?= e(url('/reports', $base)) ?>">Alle Kategorien</a> › <?= e($parentCat['name']) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$expenses): ?>
                    <div class="text-body-secondary small">Keine Ausgaben im Zeitraum.</div>
                <?php else: ?>
                    <div class="chart-box" style="height: 240px"><canvas id="donut" role="img" aria-label="Ausgaben nach Kategorie, Details in der Tabelle"></canvas></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Kategorie</th><th class="text-end">Betrag</th><th class="text-end d-none d-sm-table-cell">Anteil</th><th class="text-end d-none d-md-table-cell">Ø Monat</th></tr></thead>
                    <tbody>
                    <?php $months = max(1, count(\App\Services\ReportService::monthRange($from, $to))); ?>
                    <?php foreach ($expenses as $idx => $c): ?>
                        <tr>
                            <td>
                                <i class="bi bi-<?= e($c['icon']) ?>" style="color: <?= e($c['color']) ?>" <?= $parentCat ? 'data-series="' . ($idx < 7 ? $idx : -1) . '"' : '' ?>></i>
                                <?php if (!$parentCat && $c['has_children']): ?>
                                    <a href="<?= e(url('/reports', $base + ['parent' => $c['id']])) ?>"><?= e($c['name']) ?></a> <i class="bi bi-chevron-right small text-body-secondary"></i>
                                <?php else: ?>
                                    <a class="text-body" href="<?= e(url('/transactions', ['category_id' => $c['id'] ?? 'none', 'from' => $from, 'to' => $to, 'type' => 'expense'])) ?>"><?= e($c['name']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="table-amount"><?= money($c['total']) ?></td>
                            <td class="text-end d-none d-sm-table-cell small text-body-secondary"><?= e(number_format($c['total'] / $expTotal * 100, 1, ',', '.')) ?> %</td>
                            <td class="table-amount d-none d-md-table-cell text-body-secondary"><?= money($c['total'] / $months) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-transparent"><strong>Einnahmen und Ausgaben je Monat</strong></div>
    <div class="card-body"><div class="chart-box"><canvas id="monthly" role="img" aria-label="Einnahmen und Ausgaben je Monat"></canvas></div></div>
</div>

<div class="card mb-3">
    <div class="card-header bg-transparent"><strong>Ausgaben je Monat nach Kategorie</strong></div>
    <div class="card-body"><div class="chart-box lg"><canvas id="stacked" role="img" aria-label="Ausgaben je Monat nach Kategorie"></canvas></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-transparent"><strong>Einnahmen nach Kategorie</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($incomes as $c): ?>
                    <li class="list-group-item d-flex justify-content-between"><span><i class="bi bi-<?= e($c['icon']) ?>" style="color: <?= e($c['color']) ?>"></i> <?= e($c['name']) ?></span><span class="amount"><?= money($c['total']) ?> <small class="text-body-secondary fw-normal"><?= e(number_format($c['total'] / $incTotal * 100, 0)) ?> %</small></span></li>
                <?php endforeach; ?>
                <?php if (!$incomes): ?><li class="list-group-item small text-body-secondary">Keine Einnahmen im Zeitraum.</li><?php endif; ?>
            </ul>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-transparent"><strong>Größte Empfänger</strong></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($payees as $p): ?>
                    <li class="list-group-item d-flex justify-content-between gap-2">
                        <a class="text-body text-truncate" href="<?= e(url('/transactions', ['q' => $p['payee'], 'from' => $from, 'to' => $to])) ?>"><?= e($p['payee']) ?></a>
                        <span class="amount text-nowrap"><?= money($p['total']) ?> <small class="text-body-secondary fw-normal"><?= (int) $p['cnt'] ?>×</small></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$payees): ?><li class="list-group-item small text-body-secondary">Keine Daten.</li><?php endif; ?>
            </ul>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const donut = <?= json_encode(array_map(fn ($c) => ['name' => $c['name'], 'total' => round($c['total'], 2), 'color' => $c['color']], $donut)) ?>;
        const monthly = <?= json_encode(['labels' => array_map('month_label', array_keys($monthly)), 'income' => array_column($monthly, 'income'), 'expense' => array_column($monthly, 'expense')]) ?>;
        const stacked = <?= json_encode($stacked) ?>;
        const drill = <?= json_encode((bool) $parentCat) ?>;
        document.querySelectorAll('[data-series]').forEach(el => {
            const i = parseInt(el.dataset.series, 10);
            el.style.color = i < 0 ? HB.otherColor() : HB.series(i);
        });

        if (document.getElementById('donut')) {
            new Chart(document.getElementById('donut'), {
                type: 'doughnut',
                // Im Drilldown teilen sich Unterkategorien die Farbe der Hauptkategorie → Palettentöne in fester Reihenfolge
                data: { labels: donut.map(d => d.name), datasets: [{ data: donut.map(d => d.total),
                    backgroundColor: donut.map((d, i) => !d.color ? HB.otherColor() : (drill ? HB.series(i) : d.color)) }] },
                options: { maintainAspectRatio: false, cutout: '62%', interaction: { mode: 'nearest', intersect: true },
                    plugins: { legend: { position: 'right', labels: { boxWidth: 8 } } } },
            });
        }

        new Chart(document.getElementById('monthly'), {
            type: 'bar',
            data: { labels: monthly.labels, datasets: [
                { label: 'Einnahmen', data: monthly.income, backgroundColor: HB.series(0), borderColor: HB.surface(), borderWidth: 1 },
                { label: 'Ausgaben', data: monthly.expense, backgroundColor: HB.series(1), borderColor: HB.surface(), borderWidth: 1 },
            ] },
            options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: HB.euroTick } }, x: { grid: { display: false } } } },
        });

        new Chart(document.getElementById('stacked'), {
            type: 'bar',
            data: { labels: stacked.labels, datasets: stacked.datasets.map(d => ({
                label: d.label, data: d.data, backgroundColor: d.label === 'Übrige' ? HB.otherColor() : d.color,
                borderColor: HB.surface(), borderWidth: { top: 2 }, borderRadius: 0,
            })) },
            options: { maintainAspectRatio: false, scales: { x: { stacked: true, grid: { display: false } }, y: { stacked: true, beginAtZero: true, ticks: { callback: HB.euroTick } } },
                plugins: { legend: { position: 'bottom' } } },
        });
    });
</script>
