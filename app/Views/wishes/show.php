<?php
use App\Controllers\WishController;

$p = $w['plan'];
$id = (int) $w['id'];
$monthYear = fn (string $d) => month_de((int) substr($d, 5, 2)) . ' ' . substr($d, 0, 4);
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <?php if ($w['image_path']): ?>
                <a href="<?= e(url("/wishes/$id/image")) ?>" target="_blank" rel="noopener">
                    <img class="card-img-top wish-img-large" src="<?= e(url("/wishes/$id/image")) ?>" alt="<?= e($w['title']) ?>">
                </a>
            <?php endif; ?>
            <div class="card-body">
                <div class="d-flex justify-content-between gap-2">
                    <h2 class="h5 mb-1"><?= e($w['title']) ?></h2>
                    <?php if ($w['price'] !== null): ?><div class="fs-5 amount text-nowrap"><?= money($w['price']) ?></div><?php endif; ?>
                </div>
                <div class="small text-body-secondary mb-2">
                    <?= e(implode(' · ', array_filter([
                        $w['occasion'],
                        $w['for_user_name'] ? 'für ' . $w['for_user_name'] : null,
                        $w['wanted_by'] ? 'Wunschtermin ' . date_de($w['wanted_by']) : null,
                    ]))) ?>
                </div>
                <?php if ($w['status'] === 'fulfilled'): ?>
                    <div class="alert alert-success py-2 small"><i class="bi bi-check2-circle"></i> Erfüllt am <?= e(date_de($w['fulfilled_at'])) ?></div>
                <?php endif; ?>
                <?php if ($w['description']): ?>
                    <p class="mb-2" style="white-space: pre-line"><?= e($w['description']) ?></p>
                <?php endif; ?>
                <?php if ($w['link']): ?>
                    <a href="<?= e($w['link']) ?>" target="_blank" rel="noopener noreferrer" class="small text-break"><i class="bi bi-box-arrow-up-right"></i> <?= e(parse_url($w['link'], PHP_URL_HOST) ?: $w['link']) ?></a>
                <?php endif; ?>
                <?php if (!$w['image_path'] && !$w['description']): ?>
                    <p class="small text-body-secondary mb-0">Noch ohne Bild und Beschreibung – <a href="<?= e(url("/wishes/$id/edit")) ?>">ergänzen</a>.</p>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-primary" href="<?= e(url("/wishes/$id/edit")) ?>"><i class="bi bi-pencil"></i> Bearbeiten</a>
                <form method="post" action="<?= e(url("/wishes/$id/status")) ?>">
                    <?= csrf_field() ?>
                    <?php if ($w['status'] === 'open'): ?>
                        <button class="btn btn-sm btn-success"><i class="bi bi-check2"></i> Erfüllt / gekauft</button>
                    <?php else: ?>
                        <input type="hidden" name="status" value="open">
                        <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-counterclockwise"></i> Wieder offen</button>
                    <?php endif; ?>
                </form>
                <form method="post" action="<?= e(url("/wishes/$id/delete")) ?>" class="ms-auto" onsubmit="return HB.confirmSubmit(this, 'Wunsch samt Bild und Sparplan löschen?')">
                    <?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Löschen</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php if (!$p['has_plan']): ?>
            <div class="card empty-state">
                <i class="bi bi-piggy-bank"></i>
                <p>Für diesen Wunsch gibt es noch keinen Sparplan. Mit einer monatlichen Rate siehst du, ab wann du ihn dir leisten kannst.</p>
                <?php if ($w['status'] === 'open'): ?><a class="btn btn-primary" href="<?= e(url("/wishes/$id/edit", ['plan' => 1])) ?>#plan">Sparplan einrichten</a><?php endif; ?>
            </div>
        <?php elseif (!$w['visible']): ?>
            <div class="card empty-state"><i class="bi bi-lock"></i><p>Der Sparplan liegt auf einem Konto, das du nicht sehen darfst.</p></div>
        <?php else: ?>
            <div class="card mb-3">
                <div class="card-header d-flex align-items-center">
                    <span class="fw-semibold"><i class="bi bi-piggy-bank"></i> Sparplan</span>
                    <a class="ms-auto small" href="<?= e(url('/wishes/plans')) ?><?= $rec ? '#rec-' . (int) $rec['id'] : '' ?>">Alle Sparpläne</a>
                </div>
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-end">
                        <div>
                            <div class="stat-label small text-body-secondary">Angespart heute</div>
                            <div class="fs-4 amount"><?= money($p['saved'], true) ?></div>
                        </div>
                        <?php if ($p['price'] !== null): ?>
                            <div class="text-end small text-body-secondary">
                                <?= e(number_format((float) $p['progress'], 0, ',', '.')) ?> % von <?= money($p['price'], true) ?>
                                <?php if (!$p['reached']): ?><br>noch <?= money($p['remaining'], true) ?><?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($p['progress'] !== null): ?>
                        <div class="progress mt-2" role="progressbar" aria-label="Angespart" aria-valuenow="<?= (int) $p['progress'] ?>" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar <?= $p['reached'] ? 'bg-success' : '' ?>" style="width: <?= round($p['progress'], 1) ?>%"></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($w['status'] === 'open'): ?>
                        <?php if ($p['reached']): ?>
                            <div class="alert alert-success mt-3 mb-0"><i class="bi bi-check2-circle"></i> Der Betrag ist angespart – der Wunsch kann erfüllt werden.</div>
                        <?php elseif ($p['reach_date']): ?>
                            <div class="alert <?= $p['late'] ? 'alert-warning' : 'alert-info' ?> mt-3 mb-0">
                                <i class="bi bi-flag"></i> Hältst du den Sparplan ein, ist der Betrag am <strong><?= e(date_de($p['reach_date'])) ?></strong>
                                (<?= e($monthYear($p['reach_date'])) ?>) zusammen – noch <?= (int) $p['rates_left'] ?> <?= $p['rates_left'] === 1 ? 'Rate' : 'Raten' ?>.
                                <?php if ($p['late']): ?>
                                    <br>Das ist nach dem Wunschtermin <?= e(date_de($w['wanted_by'])) ?>.
                                    <?php if ($p['rate_for_wish_date']): ?>Dafür wären <strong><?= money($p['rate_for_wish_date'], true) ?></strong> im Monat nötig.<?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($p['price'] === null): ?>
                            <div class="alert alert-light border mt-3 mb-0">Ohne Preis lässt sich kein Zieldatum berechnen. <a href="<?= e(url("/wishes/$id/edit")) ?>">Preis eintragen</a></div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <dl class="row small mt-3 mb-0">
                        <dt class="col-5 fw-normal text-body-secondary">Rate</dt>
                        <dd class="col-7 mb-1"><?= money($p['rate'], true) ?> im Monat<?= $p['next_rate'] ? ', nächste am ' . e(date_de($p['next_rate'])) : '' ?></dd>
                        <dt class="col-5 fw-normal text-body-secondary">Bezahlt aus Posten</dt>
                        <dd class="col-7 mb-1">
                            <?php if ($rec): ?>
                                <a href="<?= e(url('/wishes/plans')) ?>#rec-<?= (int) $rec['id'] ?>"><?= e(WishController::recurringName($rec)) ?></a>
                                <?php if ($usage): ?>
                                    <div class="<?= $usage['over'] ? 'text-danger fw-semibold' : 'text-body-secondary' ?>">
                                        <?php if ($usage['over']): ?><i class="bi bi-exclamation-triangle-fill"></i> Posten um <?= money(-$usage['free'], true) ?>/Monat überschritten
                                        <?php else: ?>dort noch <?= money($usage['free'], true) ?>/Monat frei<?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            <?php elseif ($w['plan_recurring_id']): ?>
                                <span class="text-body-secondary">Posten nicht sichtbar</span>
                            <?php else: ?>
                                <span class="text-body-secondary">keiner</span> – <a href="<?= e(url("/wishes/$id/edit")) ?>#plan">zuordnen</a>
                            <?php endif; ?>
                        </dd>
                        <dt class="col-5 fw-normal text-body-secondary">Erspartes liegt auf</dt>
                        <dd class="col-7 mb-1">
                            <?= e($w['plan_account_name'] ?? '–') ?>
                            <?php if ($account && $w['coverage'] && $p['saved'] > 0 && $w['status'] === 'open'): ?>
                                <div class="<?= $w['coverage']['missing'] > 0 ? 'text-danger' : 'text-success' ?>">
                                    <?= $w['coverage']['missing'] > 0
                                        ? 'Kontostand ' . money($account['balance']) . ' – es fehlen ' . money($w['coverage']['missing'], true)
                                        : '<i class="bi bi-check2-circle"></i> durch den Kontostand gedeckt' ?>
                                </div>
                            <?php endif; ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <?php if ($schedule): ?>
                <div class="card">
                    <div class="card-header fw-semibold">Vorschau der Raten</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-card mb-0">
                            <thead><tr><th>Datum</th><th class="text-end">Rate</th><th class="text-end">Stand danach</th></tr></thead>
                            <tbody>
                            <?php $n = count($schedule); foreach ($schedule as $i => $row): ?>
                                <?php if ($n > 14 && $i === 11): ?>
                                    <tr><td colspan="3" class="text-center text-body-secondary small">… <?= $n - 13 ?> weitere Raten …</td></tr>
                                <?php endif; ?>
                                <?php if ($n > 14 && $i >= 11 && $i < $n - 2) { continue; } ?>
                                <tr class="<?= $i === $n - 1 && $p['price'] !== null && $row['saved'] >= $p['price'] ? 'table-success' : '' ?>">
                                    <td><?= e(date_de($row['date'])) ?></td>
                                    <td class="text-end amount"><?= money($row['rate'], true) ?></td>
                                    <td class="text-end amount"><?= money($row['saved'], true) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
