<?php use App\Core\View; ?>
<div class="row g-3 mb-3">
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Fixe Einnahmen / Monat</div>
        <div class="stat-value text-success"><?= money($income) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Fixkosten / Monat</div>
        <div class="stat-value text-danger"><?= money(abs($expense)) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Frei verfügbar</div>
        <div class="stat-value <?= money_class($income + $expense) ?>"><?= money($income + $expense) ?></div>
    </div></div></div>
</div>
<p class="small text-body-secondary">Quartals-, Halbjahres- und Jahresbeträge sind anteilig auf den Monat umgerechnet. Fällige Termine werden automatisch gebucht.</p>

<?php if (!$rows): ?>
    <div class="card empty-state">
        <i class="bi bi-arrow-repeat"></i>
        <p>Noch keine wiederkehrenden Buchungen. Lege Miete, Versicherungen, Abos, Gehalt usw. an – sie fließen in die Prognose ein.</p>
        <a href="<?= e(url('/recurring/new')) ?>" class="btn btn-primary">Anlegen</a>
    </div>
<?php else: ?>
    <div class="card">
        <div class="list-group list-group-flush tx-list">
            <?php foreach ($rows as $r): ?>
                <a class="list-group-item list-group-item-action <?= $r['active'] ? '' : 'opacity-50' ?>" href="<?= e(url("/recurring/{$r['id']}/edit")) ?>">
                    <?= View::partial('partials/category_badge', $r['to_account_id'] ? ['icon' => 'arrow-left-right', 'color' => '#0d6efd'] : ['icon' => $r['category_icon'], 'color' => $r['category_color']]) ?>
                    <div class="tx-main">
                        <div class="tx-title"><?= e($r['payee'] ?: ($r['category_name'] ?? ($r['to_account_id'] ? 'Umbuchung' : 'Dauerauftrag'))) ?></div>
                        <div class="tx-sub">
                            <?= e(interval_label($r['interval'])) ?> · <?= e($r['account_name']) ?><?= $r['to_account_id'] ? ' → ' . e($r['to_account_name']) : '' ?>
                            <?php if (!$r['active']): ?> · pausiert
                            <?php elseif ($r['next']): ?> · nächste: <?= e(date_de($r['next'])) ?>
                            <?php else: ?> · beendet<?php endif; ?>
                            <?php if (!$r['auto_book']): ?> · <span title="Wird nur in der Prognose berücksichtigt">nur Prognose</span><?php endif; ?>
                            <?php if ($r['loan_id']): ?> · <i class="bi bi-bank"></i> Kredit<?php endif; ?>
                        </div>
                    </div>
                    <div class="amount <?= $r['to_account_id'] ? 'text-primary' : money_class($r['amount']) ?>"><?= money($r['amount']) ?></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
