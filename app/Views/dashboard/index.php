<?php
use App\Core\View;

$saldo = $month['income'] + $month['expense'];
$catTotal = array_sum(array_column($categories, 'total')) ?: 1;
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-label">Kontostand gesamt</div>
            <div class="stat-value <?= $total < 0 ? 'text-danger' : '' ?>"><?= money($total, true) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-label">Einnahmen <?= e(month_de((int) date('n'))) ?></div>
            <div class="stat-value text-success"><?= money($month['income']) ?></div>
            <div class="small text-body-secondary">Vormonat <?= money($prevMonth['income']) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-label">Ausgaben <?= e(month_de((int) date('n'))) ?></div>
            <div class="stat-value text-danger"><?= money(abs($month['expense'])) ?></div>
            <div class="small text-body-secondary">Vormonat <?= money(abs($prevMonth['expense'])) ?></div>
        </div></div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card stat-card h-100"><div class="card-body">
            <div class="stat-label">Saldo Monat</div>
            <div class="stat-value <?= money_class($saldo) ?>"><?= money($saldo) ?></div>
            <?php if ($loanDebt > 0): ?><div class="small text-body-secondary">Restschuld Kredite <?= money($loanDebt) ?></div><?php endif; ?>
        </div></div>
    </div>
</div>

<?php if ($uncategorized > 0): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2 py-2">
        <i class="bi bi-tags"></i>
        <div class="flex-grow-1"><?= (int) $uncategorized === 1 ? 'Eine Buchung hat' : (int) $uncategorized . ' Buchungen haben' ?> noch keine Kategorie.</div>
        <a class="btn btn-sm btn-warning" href="<?= e(url('/transactions', ['category_id' => 'none'])) ?>">Zuordnen</a>
    </div>
<?php endif; ?>
<?php if ($forecastMin['negative_from']): ?>
    <div class="alert alert-danger d-flex align-items-center gap-2 py-2">
        <i class="bi bi-exclamation-triangle"></i>
        <div class="flex-grow-1">Laut Prognose wird der Gesamtsaldo ab <?= e(date_de($forecastMin['negative_from'])) ?> negativ.</div>
        <a class="btn btn-sm btn-outline-danger" href="<?= e(url('/forecast')) ?>">Prognose</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-transparent d-flex align-items-center">
                <strong>Konten</strong>
                <a class="ms-auto small" href="<?= e(url('/accounts')) ?>">Alle</a>
            </div>
            <div class="list-group list-group-flush tx-list">
                <?php foreach ($accounts as $a): ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url('/transactions', ['account_id' => $a['id']])) ?>">
                        <span class="acc-bar" style="background: <?= e($a['color']) ?>"></span>
                        <div class="tx-main">
                            <div class="tx-title"><?= e($a['name']) ?></div>
                            <div class="tx-sub"><?= e(account_type_label($a['type'])) ?></div>
                        </div>
                        <div class="amount <?= money_class($a['balance']) ?>"><?= money($a['balance']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-transparent d-flex align-items-center">
                <strong>Letzte Buchungen</strong>
                <a class="ms-auto small" href="<?= e(url('/transactions')) ?>">Alle</a>
            </div>
            <div class="list-group list-group-flush tx-list">
                <?php if (!$recent): ?>
                    <div class="empty-state small">Noch keine Buchungen. <a href="<?= e(url('/transactions/new')) ?>">Jetzt erfassen</a> oder <a href="<?= e(url('/import')) ?>">Kontoauszug importieren</a>.</div>
                <?php endif; ?>
                <?php foreach ($recent as $t): $isTransfer = (bool) $t['transfer_group']; ?>
                    <a class="list-group-item list-group-item-action" href="<?= e(url("/transactions/{$t['id']}/edit")) ?>">
                        <?= View::partial('partials/category_badge', $isTransfer ? ['icon' => 'arrow-left-right', 'color' => '#0d6efd'] : ['icon' => $t['category_icon'], 'color' => $t['category_color']]) ?>
                        <div class="tx-main">
                            <div class="tx-title"><?= e($t['payee'] ?: ($t['purpose'] ?: ($isTransfer ? 'Umbuchung' : 'Buchung'))) ?></div>
                            <div class="tx-sub"><?= e(date_de($t['booking_date'])) ?> · <?= e($t['category_name'] ?? ($isTransfer ? 'Umbuchung' : 'ohne Kategorie')) ?></div>
                        </div>
                        <div class="amount <?= $isTransfer ? 'text-primary' : money_class($t['amount']) ?>"><?= money($t['amount']) ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-transparent d-flex align-items-center">
                <strong>Ausgaben diesen Monat</strong>
                <a class="ms-auto small" href="<?= e(url('/reports')) ?>">Auswertung</a>
            </div>
            <div class="card-body">
                <?php if (!$categories): ?>
                    <div class="text-body-secondary small">Noch keine Ausgaben in diesem Monat.</div>
                <?php endif; ?>
                <?php foreach ($categories as $c): ?>
                    <div class="mb-2">
                        <div class="d-flex justify-content-between small">
                            <span><i class="bi bi-<?= e($c['icon']) ?>" style="color: <?= e($c['color']) ?>"></i> <?= e($c['name']) ?></span>
                            <span class="amount"><?= money($c['total']) ?></span>
                        </div>
                        <div class="progress progress-thin"><div class="progress-bar" style="width: <?= round($c['total'] / $catTotal * 100) ?>%; background: <?= e($c['color']) ?>"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-transparent d-flex align-items-center">
                <strong>Prognose 3 Monate</strong>
                <a class="ms-auto small" href="<?= e(url('/forecast')) ?>">Details</a>
            </div>
            <div class="card-body"><div class="chart-box" style="height: 180px"><canvas id="miniForecast"></canvas></div></div>
        </div>

        <div class="card mb-3">
            <div class="card-header bg-transparent d-flex align-items-center">
                <strong>Nächste Fixkosten</strong>
                <a class="ms-auto small" href="<?= e(url('/recurring')) ?>">Alle</a>
            </div>
            <div class="list-group list-group-flush tx-list">
                <?php if (!$upcoming): ?><div class="p-3 small text-body-secondary">In den nächsten 30 Tagen steht nichts an.</div><?php endif; ?>
                <?php foreach ($upcoming as $u): ?>
                    <div class="list-group-item">
                        <?= View::partial('partials/category_badge', ['icon' => $u['to_account_id'] ? 'arrow-left-right' : $u['category_icon'], 'color' => $u['to_account_id'] ? '#0d6efd' : $u['category_color'], 'size' => 'sm']) ?>
                        <div class="tx-main">
                            <div class="tx-title"><?= e($u['payee'] ?: ($u['category_name'] ?? 'Dauerauftrag')) ?></div>
                            <div class="tx-sub"><?= e(date_de($u['date'])) ?> · <?= e($u['account_name']) ?></div>
                        </div>
                        <div class="amount <?= money_class($u['amount']) ?>"><?= money($u['amount']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const data = <?= json_encode($mini) ?>;
        new Chart(document.getElementById('miniForecast'), {
            type: 'line',
            data: { labels: data.labels, datasets: [{ label: 'Gesamt', data: data.data, borderColor: HB.series(0), backgroundColor: HB.series(0) + '1f', fill: 'origin', tension: .25 }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { maxTicksLimit: 5 } }, y: { ticks: { callback: HB.euroTick } } } },
        });
    });
</script>
