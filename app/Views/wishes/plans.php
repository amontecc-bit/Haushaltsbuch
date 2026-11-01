<?php
use App\Controllers\WishController;
use App\Core\View;

$kindIcon = fn (array $r) => $r['to_account_id'] ? 'arrow-left-right text-primary' : ((float) $r['amount'] > 0 ? 'plus-circle text-success' : 'dash-circle text-danger');
$returnTo = '/wishes/plans';

/** Tabelle der Wünsche eines Postens */
$table = function (array $wishes) use ($coverage): void { ?>
    <div class="table-responsive">
        <table class="table table-sm table-card align-middle mb-0">
            <thead><tr>
                <th>Wunsch</th>
                <th class="text-end">Rate / Monat</th>
                <th class="text-end">Angespart</th>
                <th class="d-none d-md-table-cell">Erreichbar</th>
                <th>Deckung</th>
            </tr></thead>
            <tbody>
            <?php foreach ($wishes as $w): $p = $w['plan']; $c = $coverage[(int) $w['id']] ?? null; ?>
                <tr>
                    <td>
                        <a href="<?= e(url("/wishes/{$w['id']}")) ?>"><?= e($w['title']) ?></a>
                        <?php if ($w['wanted_by']): ?><div class="small text-body-secondary">Wunschtermin <?= e(date_de($w['wanted_by'])) ?></div><?php endif; ?>
                    </td>
                    <td class="text-end amount"><?= $p['running'] ? money($p['rate'], true) : '<span class="text-body-secondary">–</span>' ?></td>
                    <td class="text-end amount">
                        <?= money($p['saved'], true) ?>
                        <?php if ($p['price'] !== null): ?><div class="small text-body-secondary">von <?= money($p['price'], true) ?></div><?php endif; ?>
                    </td>
                    <td class="d-none d-md-table-cell small">
                        <?php if ($p['reached']): ?>
                            <span class="text-success"><i class="bi bi-check2"></i> erreicht</span>
                        <?php elseif ($p['reach_date']): ?>
                            <span class="<?= $p['late'] ? 'text-warning-emphasis' : '' ?>"><?= e(date_de($p['reach_date'])) ?></span>
                            <?php if ($p['late']): ?><i class="bi bi-exclamation-triangle text-warning" title="Wunschtermin wird verfehlt"></i><?php endif; ?>
                        <?php else: ?>–<?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if (!$c || $p['saved'] === 0): ?>
                            <span class="text-body-secondary">–</span>
                        <?php elseif ($c['missing'] > 0): ?>
                            <span class="text-danger">fehlen <?= money($c['missing'], true) ?></span>
                        <?php else: ?>
                            <span class="text-success"><i class="bi bi-check2-circle"></i> gedeckt</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php };
?>
<?= View::partial('wishes/tabs', ['tab' => 'plans']) ?>

<p class="small text-body-secondary">
    Jeder Sparplan wird aus einem Posten (Fixkosten / Dauerauftrag) bezahlt. Hier siehst du, wie stark die Posten durch die
    Sparpläne belegt sind und ob das bisher Ersparte auf dem Konto der Einnahmeseite tatsächlich vorhanden ist.
    Gedeckt wird in der Reihenfolge der Wunschtermine.
</p>

<?php if (!$groups && !$without): ?>
    <div class="card empty-state">
        <i class="bi bi-piggy-bank"></i>
        <p>Noch keine Sparpläne. Lege bei einem Wunsch einen Sparplan an und ordne ihn einem Posten zu,
            z. B. dem monatlichen Dauerauftrag aufs Sparkonto.</p>
        <div class="d-flex gap-2 justify-content-center flex-wrap">
            <a class="btn btn-primary" href="<?= e(url('/wishes/new')) ?>"><i class="bi bi-plus-lg"></i> Wunsch mit Sparplan</a>
            <a class="btn btn-outline-secondary" href="<?= e(url('/wishes')) ?>">Zur Wunschliste</a>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($groups as $g): $r = $g['rec']; $u = $g['usage']; $pct = $u['capacity'] > 0 ? min(100, $u['used'] / $u['capacity'] * 100) : 100; ?>
    <div class="card mb-3 <?= $u['over'] ? 'border-warning' : '' ?>" id="rec-<?= (int) $r['id'] ?>">
        <div class="card-header d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-<?= $kindIcon($r) ?>"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold"><?= e(WishController::recurringName($r)) ?></div>
                <div class="small text-body-secondary">
                    <?= e($r['account_name']) ?><?= $r['to_account_name'] ? ' → ' . e($r['to_account_name']) : ($r['counterpart_account_name'] ? ' → ' . e($r['counterpart_account_name']) : '') ?>
                    · <?= money(\App\Services\WishService::recurringMonthly($r), true) ?> / Monat<?= $r['interval'] !== 'monthly' ? ' (' . e(interval_label($r['interval'])) . ' anteilig)' : '' ?>
                </div>
            </div>
            <a class="btn btn-sm btn-outline-secondary" href="<?= e(url("/recurring/{$r['id']}/edit", ['return' => $returnTo])) ?>"><i class="bi bi-pencil"></i> Posten</a>
        </div>
        <div class="card-body pb-2">
            <div class="progress mb-1" role="progressbar" aria-label="Belegt" aria-valuenow="<?= (int) $pct ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar <?= $u['over'] ? 'bg-danger' : ($pct >= 90 ? 'bg-warning' : '') ?>" style="width: <?= round($pct, 1) ?>%"></div>
            </div>
            <div class="d-flex flex-wrap justify-content-between small">
                <span>Sparpläne <span class="amount"><?= money($u['used'], true) ?></span> von <span class="amount"><?= money($u['capacity'], true) ?></span></span>
                <?php if ($u['over']): ?>
                    <span class="text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill"></i> um <?= money(-$u['free'], true) ?> überschritten</span>
                <?php else: ?>
                    <span class="text-success">noch frei <?= money($u['free'], true) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($u['capacity'] === 0): ?>
                <div class="alert alert-warning small py-2 mt-2 mb-0">Der Posten ist pausiert oder beendet – die Sparpläne haben keine Deckung mehr.</div>
            <?php elseif ($u['over']): ?>
                <div class="alert alert-warning small py-2 mt-2 mb-0">Die Sparraten sind höher als der Posten. Erhöhe den Posten, senke Raten oder ordne einen Sparplan einem anderen Posten zu.</div>
            <?php endif; ?>
        </div>
        <?php $table($g['wishes']); ?>
        <div class="card-footer bg-transparent">
            <a class="small" href="<?= e(url('/wishes/new', ['recurring_id' => $r['id']])) ?>"><i class="bi bi-plus-lg"></i> Weiteren Wunsch über diesen Posten ansparen</a>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($without): ?>
    <div class="card mb-3">
        <div class="card-header">
            <div class="fw-semibold"><i class="bi bi-question-circle"></i> Ohne Posten</div>
            <div class="small text-body-secondary">Diese Sparpläne sind keinem Posten zugeordnet – ob die Raten bezahlbar sind, wird nicht geprüft.</div>
        </div>
        <?php $table($without); ?>
    </div>
<?php endif; ?>

<?php if ($accounts): ?>
    <h2 class="h5 mt-4">Deckung je Konto</h2>
    <div class="row g-3 mb-3">
        <?php foreach ($accounts as $acc): $miss = $acc['missing'] ?? 0; ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 <?= $miss > 0 ? 'border-danger' : '' ?>">
                    <div class="card-body">
                        <div class="fw-semibold"><span style="color: <?= e($acc['color']) ?>">●</span> <?= e($acc['name']) ?></div>
                        <dl class="row small mb-0 mt-2">
                            <dt class="col-7 fw-normal">Kontostand heute</dt><dd class="col-5 text-end amount mb-1"><?= money($acc['balance']) ?></dd>
                            <dt class="col-7 fw-normal"><?php $n = (int) ($acc['wish_count'] ?? 0); ?>Für <?= $n === 1 ? '1 Wunsch' : "$n Wünsche" ?> angespart</dt><dd class="col-5 text-end amount mb-1"><?= money($acc['saved'] ?? 0, true) ?></dd>
                            <?php if ($miss > 0): ?>
                                <dt class="col-7 text-danger">Es fehlen</dt><dd class="col-5 text-end amount text-danger fw-semibold mb-0"><?= money($miss, true) ?></dd>
                            <?php else: ?>
                                <dt class="col-7 text-success">Gedeckt, übrig</dt><dd class="col-5 text-end amount text-success mb-0"><?= money($acc['balance_cents'] - ($acc['saved'] ?? 0), true) ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                    <div class="card-footer bg-transparent small">
                        <a href="<?= e(url('/transactions', ['account_id' => $acc['id']])) ?>">Buchungen des Kontos</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($hidden): ?>
    <p class="small text-body-secondary"><?= (int) $hidden ?> weitere Sparpläne liegen auf Konten, die du nicht sehen darfst.</p>
<?php endif; ?>
