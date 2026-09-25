<?php
$accountsById = array_column($result['accounts'], null, 'id');
$start = $result['total'][0] ?? 0;
$end = end($result['total']) ?: 0;
$varTotal = array_sum($result['variable']);
?>
<form method="get" class="card mb-3">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <div class="btn-group" role="group" aria-label="Zeitraum">
            <?php foreach ([3, 6, 12, 24, 36] as $m): ?>
                <input type="radio" class="btn-check" name="months" id="m<?= $m ?>" value="<?= $m ?>" <?= checked($m === $months) ?> onchange="this.form.submit()">
                <label class="btn btn-outline-primary btn-sm" for="m<?= $m ?>"><?= $m ?> M</label>
            <?php endforeach; ?>
        </div>
        <label class="small text-body-secondary ms-2" for="avg">Ø variable Ausgaben aus</label>
        <select name="avg" id="avg" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
            <?php foreach ([0 => 'nicht berücksichtigen', 3 => '3 Monaten', 6 => '6 Monaten', 12 => '12 Monaten'] as $k => $l): ?>
                <option value="<?= $k ?>" <?= selected($k, $avg) ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="view" class="form-select form-select-sm w-auto ms-auto" onchange="this.form.submit()">
            <option value="total" <?= selected('total', $viewMode) ?>>Summe aller Konten</option>
            <option value="accounts" <?= selected('accounts', $viewMode) ?>>Je Konto</option>
        </select>
    </div>
</form>

<?php if ($result['min']['negative_from']): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <strong>Achtung:</strong> Der Gesamtsaldo wird voraussichtlich ab <?= e(date_de($result['min']['negative_from'])) ?> negativ. Tiefster Stand: <?= money($result['min']['value']) ?> am <?= e(date_de($result['min']['date'])) ?>.</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label">Heute</div><div class="stat-value"><?= money($start) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label">In <?= $months ?> Monaten</div><div class="stat-value <?= money_class($end) ?>"><?= money($end) ?></div><div class="small <?= money_class($end - $start) ?>"><?= ($end - $start) >= 0 ? '+' : '' ?><?= money($end - $start) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label">Tiefster Stand</div><div class="stat-value <?= money_class($result['min']['value']) ?>"><?= money($result['min']['value']) ?></div><div class="small text-body-secondary"><?= e(date_de($result['min']['date'])) ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="stat-label">Ø variabel / Monat</div><div class="stat-value <?= money_class($varTotal) ?>"><?= money($varTotal) ?></div><div class="small text-body-secondary">ohne Fixkosten</div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body"><div class="chart-box lg"><canvas id="forecast" role="img" aria-label="Prognose der Kontostände, Monatswerte in der Tabelle"></canvas></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-transparent"><strong>Künftige feste Buchungen</strong></div>
            <div class="table-responsive" style="max-height: 480px">
                <table class="table table-sm table-hover mb-0">
                    <thead class="sticky-top"><tr><th>Datum</th><th>Bezeichnung</th><th class="d-none d-sm-table-cell">Konto</th><th class="text-end">Betrag</th></tr></thead>
                    <tbody>
                    <?php foreach (array_slice($result['events'], 0, 200) as $ev): ?>
                        <tr>
                            <td><?= e(date_de($ev['date'])) ?></td>
                            <td><a class="text-body" href="<?= e(url("/recurring/{$ev['recurring_id']}/edit")) ?>"><?= e($ev['label']) ?></a></td>
                            <td class="d-none d-sm-table-cell small"><?= e($accountsById[$ev['account_id']]['name'] ?? '') ?></td>
                            <td class="table-amount <?= money_class($ev['amount']) ?>"><?= money($ev['amount']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$result['events']): ?><tr><td colspan="4" class="small text-body-secondary">Keine wiederkehrenden Buchungen. <a href="<?= e(url('/recurring/new')) ?>">Fixkosten anlegen</a>, damit die Prognose aussagekräftig wird.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-transparent"><strong>Stand am Monatsende</strong></div>
            <div class="table-responsive" style="max-height: 300px">
                <table class="table table-sm mb-0">
                    <?php foreach ($monthEnds as $ym => $m): ?>
                        <tr><td><?= e(month_de((int) substr($ym, 5, 2)) . ' ' . substr($ym, 0, 4)) ?></td><td class="table-amount <?= money_class($m['total']) ?>"><?= money($m['total']) ?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>
        <div class="card">
            <div class="card-header bg-transparent"><strong>Annahmen je Konto</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($result['accounts'] as $a): ?>
                    <li class="list-group-item d-flex justify-content-between">
                        <span><span style="color: <?= e($a['color']) ?>">●</span> <?= e($a['name']) ?></span>
                        <span>Ø variabel <?= money($result['variable'][(int) $a['id']] ?? 0) ?> / Monat</span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <div class="card-footer small text-body-secondary">
                Variable Beträge = alle Buchungen der letzten vollen Monate ohne Daueraufträge und Umbuchungen, gleichmäßig auf die Tage verteilt.
                Konten lassen sich in den Kontoeinstellungen von der Prognose ausschließen.
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const c = <?= json_encode($chart) ?>;
        const byAccount = <?= json_encode($viewMode === 'accounts') ?>;
        const datasets = byAccount
            ? c.accounts.map(a => ({ label: a.name, data: a.data, borderColor: a.color, backgroundColor: a.color, tension: .15 }))
            : [{ label: 'Summe aller Konten', data: c.total, borderColor: HB.series(0), backgroundColor: HB.series(0) + '1f', fill: 'origin', tension: .15 }];
        new Chart(document.getElementById('forecast'), {
            type: 'line',
            data: { labels: c.labels, datasets },
            options: {
                maintainAspectRatio: false,
                plugins: { legend: { display: byAccount && datasets.length > 1, position: 'bottom' } },
                scales: {
                    x: { ticks: { maxTicksLimit: 12 }, grid: { display: false } },
                    y: { ticks: { callback: HB.euroTick },
                         grid: { color: (ctx) => ctx.tick.value === 0 ? (document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'rgba(255,255,255,.35)' : 'rgba(0,0,0,.35)') : Chart.defaults.borderColor } },
                },
            },
        });
    });
</script>
