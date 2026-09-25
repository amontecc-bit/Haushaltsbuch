<?php $totalDebt = array_sum(array_map(fn ($l) => $l['summary']['balance'], $loans)); ?>
<?php if ($loans): ?>
    <div class="card stat-card mb-3"><div class="card-body">
        <div class="stat-label">Restschuld gesamt heute</div>
        <div class="stat-value"><?= money($totalDebt, true) ?></div>
    </div></div>
<?php endif; ?>

<?php if (!$loans): ?>
    <div class="card empty-state">
        <i class="bi bi-bank"></i>
        <p>Noch keine Kredite erfasst. Lege Baufinanzierung, Autokredit usw. an – die Restschuld wird für jeden Tag berechnet.</p>
        <a class="btn btn-primary" href="<?= e(url('/loans/new')) ?>">Kredit anlegen</a>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($loans as $l): $s = $l['summary']; $principal = \App\Core\Money::toCents($l['principal']); $paid = $principal > 0 ? max(0, min(100, ($principal - $s['balance']) / $principal * 100)) : 0; ?>
        <div class="col-md-6 col-xl-4">
            <a class="card h-100 text-decoration-none text-body" href="<?= e(url("/loans/{$l['id']}")) ?>">
                <div class="card-body">
                    <div class="d-flex justify-content-between">
                        <div>
                            <div class="fw-semibold"><?= e($l['name']) ?></div>
                            <div class="small text-body-secondary"><?= e($l['lender'] ?: '') ?> · <?= e(str_replace('.', ',', rtrim(rtrim($l['interest_rate'], '0'), '.'))) ?> % · <?= $l['loan_type'] === 'annuity' ? 'Annuität' : 'Tilgungsdarlehen' ?></div>
                        </div>
                        <?php if ($s['paid_off'] && $s['balance'] === 0): ?><span class="badge text-bg-success align-self-start">getilgt</span><?php endif; ?>
                    </div>
                    <div class="fs-4 amount mt-2"><?= money($s['balance'], true) ?></div>
                    <div class="small text-body-secondary">Restschuld von <?= money($l['principal']) ?></div>
                    <div class="progress progress-thin mt-2" role="progressbar" aria-label="Getilgt" aria-valuenow="<?= (int) $paid ?>" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar" style="width: <?= round($paid, 1) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between small mt-2">
                        <span><?= e(number_format($paid, 1, ',', '.')) ?> % getilgt</span>
                        <span><?= $s['end_date'] ? 'Ende ' . e(date_de($s['end_date'])) : '' ?></span>
                    </div>
                    <?php if ($s['next_payment']): ?>
                        <div class="small text-body-secondary mt-1">Nächste Rate <?= money($s['next_payment']['payment'], true) ?> am <?= e(date_de($s['next_payment']['date'])) ?></div>
                    <?php endif; ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
