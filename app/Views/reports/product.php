<?php
$prices = array_map(fn ($h) => (float) $h['unit_price'], $history);
$total = array_sum(array_map(fn ($h) => (float) $h['total_price'], $history));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body"><div class="stat-label">Gekauft</div><div class="stat-value"><?= count($history) ?>×</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body"><div class="stat-label">Ausgegeben</div><div class="stat-value"><?= money($total) ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body"><div class="stat-label">Günstigster Preis</div><div class="stat-value"><?= $prices ? money(min($prices)) : '–' ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card"><div class="card-body"><div class="stat-label">Letzter Preis</div><div class="stat-value"><?= $prices ? money(end($prices)) : '–' ?></div></div></div></div>
</div>

<?php if (count($history) > 1): ?>
    <div class="card mb-3">
        <div class="card-header bg-transparent"><strong>Preisentwicklung (Einzelpreis)</strong></div>
        <div class="card-body"><div class="chart-box"><canvas id="price" role="img" aria-label="Preisentwicklung, Werte in der Tabelle"></canvas></div></div>
    </div>
<?php endif; ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr><th>Datum</th><th>Geschäft</th><th class="d-none d-sm-table-cell">Bezeichnung</th><th class="text-end">Menge</th><th class="text-end">Einzelpreis</th><th class="text-end">Betrag</th></tr></thead>
            <tbody>
            <?php foreach (array_reverse($history) as $h): ?>
                <tr>
                    <td><a href="<?= e(url("/purchases/{$h['purchase_id']}")) ?>"><?= e(date_de($h['purchase_date'])) ?></a></td>
                    <td><?= e($h['store'] ?: '–') ?></td>
                    <td class="d-none d-sm-table-cell small"><?= e($h['name']) ?></td>
                    <td class="text-end"><?= e(rtrim(rtrim(number_format((float) $h['quantity'], 3, ',', '.'), '0'), ',')) ?> <?= e($h['unit']) ?></td>
                    <td class="table-amount"><?= money($h['unit_price']) ?></td>
                    <td class="table-amount"><?= money($h['total_price']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if (count($history) > 1): ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const h = <?= json_encode(array_map(fn ($x) => ['d' => date_de($x['purchase_date']), 'p' => (float) $x['unit_price'], 's' => $x['store']], $history)) ?>;
        new Chart(document.getElementById('price'), {
            type: 'line',
            data: { labels: h.map(x => x.d), datasets: [{ label: 'Einzelpreis', data: h.map(x => x.p), borderColor: HB.series(0), pointRadius: 4, pointBackgroundColor: HB.series(0), pointBorderColor: HB.surface(), pointBorderWidth: 2, stepped: false, tension: 0 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false },
                tooltip: { callbacks: { afterLabel: (ctx) => h[ctx.dataIndex].s || '' } } },
                scales: { y: { ticks: { callback: (v) => HB.money(v) } }, x: { grid: { display: false } } } },
        });
    });
</script>
<?php endif; ?>
