<?php
/** Kurzstatus eines Sparplans: Fortschritt, Erreichbarkeit, Deckung, Posten-Warnung. Erwartet $w (dekoriert). */
$p = $w['plan'];
?>
<?php if ($p['has_plan'] && $w['visible']): ?>
    <?php if ($p['progress'] !== null): ?>
        <div class="progress progress-thin mt-2" role="progressbar" aria-label="Angespart" aria-valuenow="<?= (int) $p['progress'] ?>" aria-valuemin="0" aria-valuemax="100">
            <div class="progress-bar <?= $p['reached'] ? 'bg-success' : '' ?>" style="width: <?= round($p['progress'], 1) ?>%"></div>
        </div>
    <?php endif; ?>
    <div class="small mt-1">
        <span class="amount"><?= money($p['saved'], true) ?></span> angespart<?= $p['price'] !== null ? ' von ' . money($p['price'], true) : '' ?>
        <?php if ($p['running']): ?> · <?= money($p['rate'], true) ?>/Monat<?php endif; ?>
    </div>
    <div class="d-flex flex-wrap gap-1 mt-1">
        <?php if ($w['status'] === 'open'): ?>
            <?php if ($p['reached']): ?>
                <span class="badge text-bg-success"><i class="bi bi-check2"></i> Betrag erreicht</span>
            <?php elseif ($p['reach_date']): ?>
                <span class="badge <?= $p['late'] ? 'text-bg-warning' : 'text-bg-light border' ?>"><i class="bi bi-flag"></i> erreichbar ab <?= e(month_de((int) substr($p['reach_date'], 5, 2)) . ' ' . substr($p['reach_date'], 0, 4)) ?></span>
            <?php elseif ($p['price'] === null): ?>
                <span class="badge text-bg-light border">ohne Zielbetrag</span>
            <?php endif; ?>
            <?php if ($w['coverage'] && $p['saved'] > 0): ?>
                <?php if ($w['coverage']['missing'] > 0): ?>
                    <span class="badge text-bg-danger" title="Der Kontostand reicht nicht für alles Ersparte">auf dem Konto fehlen <?= money($w['coverage']['missing'], true) ?></span>
                <?php else: ?>
                    <span class="badge text-bg-success-subtle text-success-emphasis border border-success-subtle" title="Das Ersparte ist durch den Kontostand gedeckt">gedeckt</span>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($w['posten_over']): ?>
                <span class="badge text-bg-warning" title="Die Sparpläne übersteigen den zugeordneten Posten"><i class="bi bi-exclamation-triangle"></i> Posten überschritten</span>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php elseif ($p['has_plan']): ?>
    <div class="small text-body-secondary mt-1"><i class="bi bi-piggy-bank"></i> Sparplan vorhanden</div>
<?php endif; ?>
