<?php use App\Core\View; ?>
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap gap-4">
                <div><div class="stat-label small text-body-secondary">Geschäft</div><div class="fw-semibold"><?= e($p['store'] ?: '–') ?></div></div>
                <div><div class="stat-label small text-body-secondary">Datum</div><div class="fw-semibold"><?= e(date_de($p['purchase_date'])) ?></div></div>
                <div><div class="stat-label small text-body-secondary">Konto</div><div class="fw-semibold"><?= e($p['account_name'] ?: 'bar / ohne') ?></div></div>
                <div class="ms-auto text-end"><div class="stat-label small text-body-secondary">Summe</div><div class="fs-4 amount"><?= money($p['total']) ?></div></div>
            </div>
            <?php if ($p['transaction_id']): ?>
                <div class="card-footer small"><i class="bi bi-link-45deg"></i> Verknüpft mit Buchung
                    <a href="<?= e(url("/transactions/{$p['transaction_id']}/edit")) ?>"><?= e(($p['tx_payee'] ?: 'Buchung') . ' vom ' . date_de($p['tx_date']) . ' (' . money($p['tx_amount']) . ')') ?></a>
                    <?php if (abs((float) $p['tx_amount'] + (float) $p['total']) > 0.009): ?>
                        <span class="text-warning-emphasis"> – Betrag weicht um <?= money(abs((float) $p['tx_amount']) - (float) $p['total']) ?> ab</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card mb-3">
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Artikel</th><th class="text-end d-none d-sm-table-cell">Menge</th><th class="text-end d-none d-sm-table-cell">Einzelpreis</th><th class="text-end">Betrag</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $i): ?>
                        <tr>
                            <td>
                                <div><?= e($i['name']) ?></div>
                                <div class="small text-body-secondary">
                                    <?php if ($i['category_name']): ?>
                                        <i class="bi bi-<?= e($i['category_icon']) ?>" style="color: <?= e($i['category_color']) ?>"></i> <?= e(($i['category_parent'] ? $i['category_parent'] . ' › ' : '') . $i['category_name']) ?>
                                    <?php else: ?><span class="text-warning-emphasis">ohne Kategorie</span><?php endif; ?>
                                    <?php if ($i['product_id']): ?> · <a href="<?= e(url('/reports/product', ['id' => $i['product_id']])) ?>">Preisverlauf</a><?php endif; ?>
                                </div>
                            </td>
                            <td class="text-end d-none d-sm-table-cell"><?= e(rtrim(rtrim(number_format((float) $i['quantity'], 3, ',', '.'), '0'), ',')) ?> <?= e($i['unit']) ?></td>
                            <td class="text-end d-none d-sm-table-cell"><?= money($i['unit_price']) ?></td>
                            <td class="table-amount"><?= money($i['total_price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$items): ?><tr><td colspan="4" class="text-body-secondary small">Keine Einzelposten erfasst.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($p['note']): ?><p class="text-body-secondary"><i class="bi bi-chat-left-text"></i> <?= e($p['note']) ?></p><?php endif; ?>
    </div>

    <div class="col-lg-4">
        <?php if ($byCat): ?>
            <div class="card mb-3">
                <div class="card-header bg-transparent"><strong>Nach Kategorien</strong></div>
                <div class="card-body">
                    <?php $maxCat = max(array_map(fn ($c) => abs($c['total']), $byCat)) ?: 1; ?>
                    <?php foreach ($byCat as $name => $c): ?>
                        <div class="mb-2">
                            <div class="d-flex justify-content-between small"><span><?= e($name) ?></span><span class="amount"><?= money($c['total']) ?></span></div>
                            <div class="progress progress-thin"><div class="progress-bar" style="width: <?= round(max(0, $c['total']) / $maxCat * 100) ?>%; background: <?= e($c['color']) ?>"></div></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
        <?php foreach ($files as $n => $f): ?>
            <div class="card mb-3">
                <div class="card-body p-2 text-center">
                    <?php if ($f['is_pdf']): ?>
                        <a class="btn btn-outline-secondary w-100" href="<?= e(url("/purchases/{$p['id']}/file", ['n' => $n])) ?>" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Beleg (PDF) öffnen</a>
                    <?php else: ?>
                        <a href="<?= e(url("/purchases/{$p['id']}/file", ['n' => $n])) ?>" target="_blank"><img src="<?= e(url("/purchases/{$p['id']}/file", ['n' => $n])) ?>" class="img-fluid rounded" alt="Kassenbon" loading="lazy"></a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

