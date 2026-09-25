<?php
$s = $summary;
$t = $today;
$principal = \App\Core\Money::toCents($loan['principal']);
$pct = fn ($v) => str_replace('.', ',', rtrim(rtrim((string) $v, '0'), '.'));
?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Restschuld heute</div><div class="stat-value"><?= money($t['balance'], true) ?></div>
        <div class="small text-body-secondary">von <?= money($principal, true) ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Aktuelle Rate</div><div class="stat-value"><?= money($t['current_payment'], true) ?></div>
        <div class="small text-body-secondary"><?= $t['next_payment'] ? 'nächste am ' . e(date_de($t['next_payment']['date'])) : 'keine weitere Rate' ?></div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Voraussichtlich getilgt</div><div class="stat-value"><?= $t['end_date'] ? e(date_de($t['end_date'])) : '–' ?></div>
        <div class="small text-body-secondary"><?= count($schedule) ?> Raten gesamt</div>
    </div></div></div>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body">
        <div class="stat-label">Zinsen gesamt</div><div class="stat-value"><?= money($t['total_interest'], true) ?></div>
        <div class="small text-body-secondary">bisher gezahlt <?= money($t['paid_interest'], true) ?></div>
    </div></div></div>
</div>

<?php if (!$t['paid_off']): ?>
    <div class="alert alert-warning">Mit den angegebenen Werten wird der Kredit nicht vollständig getilgt (Rate zu niedrig oder Laufzeit über 60 Jahre).</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header bg-transparent"><strong>Stand zu einem beliebigen Datum</strong></div>
            <div class="card-body">
                <form method="get" class="d-flex gap-2 mb-3">
                    <input type="date" class="form-control" name="date" value="<?= e($asOf) ?>">
                    <button class="btn btn-primary">Berechnen</button>
                </form>
                <table class="table table-sm mb-0">
                    <tr><td>Restschuld am <?= e(date_de($asOf)) ?></td><td class="table-amount fw-bold"><?= money($s['balance'], true) ?></td></tr>
                    <tr><td>Gezahlte Raten</td><td class="table-amount"><?= (int) $s['payments'] ?></td></tr>
                    <tr><td>davon Zinsen</td><td class="table-amount"><?= money($s['paid_interest'], true) ?></td></tr>
                    <tr><td>davon Tilgung</td><td class="table-amount"><?= money($s['paid_principal'], true) ?></td></tr>
                    <tr><td>Sondertilgungen</td><td class="table-amount"><?= money($s['paid_special'], true) ?></td></tr>
                    <?php if ($s['balance_fixed_until'] !== null): ?>
                        <tr><td>Restschuld Ende Zinsbindung (<?= e(date_de($loan['fixed_until'])) ?>)</td><td class="table-amount"><?= money($s['balance_fixed_until'], true) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header bg-transparent"><strong>Verlauf der Restschuld</strong></div>
            <div class="card-body"><div class="chart-box"><canvas id="loanChart" role="img" aria-label="Verlauf der Restschuld, Werte in der Jahresübersicht"></canvas></div></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-transparent"><strong>Kreditdaten</strong></div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-6">Bank</dt><dd class="col-6"><?= e($loan['lender'] ?: '–') ?></dd>
                    <dt class="col-6">Vertragsnummer</dt><dd class="col-6"><?= e($loan['contract_number'] ?: '–') ?></dd>
                    <dt class="col-6">Art</dt><dd class="col-6"><?= $loan['loan_type'] === 'annuity' ? 'Annuitätendarlehen' : 'Tilgungsdarlehen' ?></dd>
                    <dt class="col-6">Sollzins</dt><dd class="col-6"><?= e($pct($loan['interest_rate'])) ?> % p. a.</dd>
                    <?php if ($loan['initial_repayment_rate']): ?><dt class="col-6">Anfängliche Tilgung</dt><dd class="col-6"><?= e($pct($loan['initial_repayment_rate'])) ?> %</dd><?php endif; ?>
                    <dt class="col-6">Auszahlung</dt><dd class="col-6"><?= e(date_de($loan['payout_date'])) ?></dd>
                    <dt class="col-6">Erste Rate</dt><dd class="col-6"><?= e(date_de($loan['first_payment_date'])) ?></dd>
                    <dt class="col-6">Zinsbindung bis</dt><dd class="col-6"><?= e(date_de($loan['fixed_until']) ?: '–') ?></dd>
                    <dt class="col-6">Konto</dt><dd class="col-6"><?= e($loan['account_name'] ?: '–') ?></dd>
                </dl>
                <?php if ($loan['note']): ?><p class="mt-2 mb-0"><?= nl2br(e($loan['note'])) ?></p><?php endif; ?>
            </div>
            <div class="card-footer small">
                <?php if ($recurring): ?>
                    <i class="bi bi-arrow-repeat"></i> Die Rate ist als <a href="<?= e(url("/recurring/{$recurring['id']}/edit")) ?>">wiederkehrende Buchung</a> hinterlegt und fließt in die Prognose ein.
                <?php elseif ($canEdit && $loan['account_id'] && $t['next_payment']): ?>
                    <form method="post" action="<?= e(url("/loans/{$loan['id']}/recurring")) ?>" class="d-flex flex-wrap gap-2 align-items-center">
                        <?= csrf_field() ?>
                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="auto_book" value="1" id="ab"><label class="form-check-label" for="ab">automatisch buchen</label></div>
                        <button class="btn btn-sm btn-outline-primary">Rate als Fixkosten anlegen</button>
                    </form>
                <?php elseif ($canEdit && !$loan['account_id']): ?>
                    Tipp: Konto hinterlegen, um die Rate als Fixkosten in die Prognose zu übernehmen.
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header bg-transparent"><strong>Sondertilgungen</strong></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($specials as $sp): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span><?= e(date_de($sp['payment_date'])) ?><?= $sp['note'] ? ' · ' . e($sp['note']) : '' ?></span>
                        <span class="d-flex align-items-center gap-2"><span class="amount"><?= money($sp['amount']) ?></span>
                        <?php if ($canEdit): ?><form method="post" action="<?= e(url("/loans/{$loan['id']}/special/{$sp['id']}/delete")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" aria-label="Löschen"><i class="bi bi-x-lg"></i></button></form><?php endif; ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if (!$specials): ?><li class="list-group-item text-body-secondary">Keine.</li><?php endif; ?>
            </ul>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url("/loans/{$loan['id']}/special")) ?>" class="card-footer d-flex gap-2 flex-wrap">
                    <?= csrf_field() ?>
                    <input type="date" class="form-control form-control-sm w-auto" name="payment_date" value="<?= e(date('Y-m-d')) ?>" required>
                    <input class="form-control form-control-sm text-end" style="width: 7rem" name="amount" inputmode="decimal" placeholder="Betrag" required>
                    <input class="form-control form-control-sm flex-grow-1" style="min-width: 6rem" name="note" placeholder="Notiz">
                    <button class="btn btn-sm btn-primary">+</button>
                </form>
            <?php endif; ?>
        </div>
        <div class="card">
            <div class="card-header bg-transparent"><strong>Zins-/Ratenänderungen</strong> <span class="small text-body-secondary">(z. B. Anschlussfinanzierung)</span></div>
            <ul class="list-group list-group-flush small">
                <?php foreach ($changes as $c): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>ab <?= e(date_de($c['valid_from'])) ?><?= $c['interest_rate'] !== null ? ' · Zins ' . e($pct($c['interest_rate'])) . ' %' : '' ?><?= $c['monthly_payment'] !== null ? ' · Rate ' . money($c['monthly_payment']) : '' ?><?= $c['note'] ? ' · ' . e($c['note']) : '' ?></span>
                        <?php if ($canEdit): ?><form method="post" action="<?= e(url("/loans/{$loan['id']}/change/{$c['id']}/delete")) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-link text-danger p-0" aria-label="Löschen"><i class="bi bi-x-lg"></i></button></form><?php endif; ?>
                    </li>
                <?php endforeach; ?>
                <?php if (!$changes): ?><li class="list-group-item text-body-secondary">Keine.</li><?php endif; ?>
            </ul>
            <?php if ($canEdit): ?>
                <form method="post" action="<?= e(url("/loans/{$loan['id']}/change")) ?>" class="card-footer d-flex gap-2 flex-wrap">
                    <?= csrf_field() ?>
                    <input type="date" class="form-control form-control-sm w-auto" name="valid_from" value="<?= e($loan['fixed_until'] ?: date('Y-m-d')) ?>" required>
                    <input class="form-control form-control-sm text-end" style="width: 6rem" name="interest_rate" inputmode="decimal" placeholder="Zins %">
                    <input class="form-control form-control-sm text-end" style="width: 7rem" name="monthly_payment" inputmode="decimal" placeholder="neue Rate">
                    <button class="btn btn-sm btn-primary">+</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-transparent"><strong>Jahresübersicht</strong></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead><tr><th>Jahr</th><th class="text-end">Raten</th><th class="text-end">Zinsen</th><th class="text-end">Tilgung</th><th class="text-end d-none d-sm-table-cell">Sondertilgung</th><th class="text-end">Restschuld</th></tr></thead>
            <tbody>
            <?php foreach ($yearly as $y): ?>
                <tr class="<?= $y['year'] === date('Y') ? 'table-active' : '' ?>">
                    <td><?= e($y['year']) ?></td>
                    <td class="table-amount"><?= money($y['payment'], true) ?></td>
                    <td class="table-amount"><?= money($y['interest'], true) ?></td>
                    <td class="table-amount"><?= money($y['principal'], true) ?></td>
                    <td class="table-amount d-none d-sm-table-cell"><?= $y['special'] ? money($y['special'], true) : '–' ?></td>
                    <td class="table-amount"><?= money($y['balance'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<details class="card">
    <summary class="card-header bg-transparent"><strong>Tilgungsplan (alle Raten)</strong></summary>
    <div class="table-responsive" style="max-height: 500px">
        <table class="table table-sm table-hover mb-0 small">
            <thead class="sticky-top"><tr><th>#</th><th>Datum</th><th class="text-end">Rate</th><th class="text-end">Zinsen</th><th class="text-end">Tilgung</th><th class="text-end">Sonder</th><th class="text-end">Restschuld</th></tr></thead>
            <tbody>
            <?php foreach ($schedule as $r): ?>
                <tr class="<?= substr($r['date'], 0, 7) === date('Y-m') ? 'table-active' : '' ?>">
                    <td><?= $r['no'] ?></td><td><?= e(date_de($r['date'])) ?></td>
                    <td class="table-amount"><?= money($r['payment'], true) ?></td>
                    <td class="table-amount"><?= money($r['interest'], true) ?></td>
                    <td class="table-amount"><?= money($r['principal'], true) ?></td>
                    <td class="table-amount"><?= $r['special'] ? money($r['special'], true) : '' ?></td>
                    <td class="table-amount"><?= money($r['balance'], true) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        HB.chartDefaults();
        const pts = <?= json_encode(array_merge(
            [['d' => date_de($loan['payout_date']), 'b' => $principal / 100]],
            array_map(fn ($r) => ['d' => date_de($r['date']), 'b' => $r['balance'] / 100], $schedule)
        )) ?>;
        const today = <?= json_encode(date_de(date('Y-m-d'))) ?>;
        new Chart(document.getElementById('loanChart'), {
            type: 'line',
            data: { labels: pts.map(p => p.d), datasets: [{ label: 'Restschuld', data: pts.map(p => p.b), borderColor: HB.series(0), backgroundColor: HB.series(0) + '1f', fill: 'origin', stepped: 'before' }] },
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } },
                scales: { x: { ticks: { maxTicksLimit: 8 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: HB.euroTick } } } },
        });
    });
</script>
