<?php
use App\Core\View;

$hasFilter = $filters['status'] !== 'open' || $filters['occasion'] !== '' || $filters['for_user_id'] || $filters['q'] !== '';
?>
<?= View::partial('wishes/tabs', ['tab' => 'list']) ?>

<div class="row g-3 mb-3">
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Offene Wünsche</div>
        <div class="stat-value"><?= money($sumPrice, true) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Sparraten / Monat</div>
        <div class="stat-value"><?= money($sumRates, true) ?></div>
    </div></div></div>
    <div class="col-4"><div class="card stat-card"><div class="card-body">
        <div class="stat-label">Auf Konten fehlt</div>
        <div class="stat-value <?= $missing > 0 ? 'text-danger' : 'text-success' ?>"><?= money($missing, true) ?></div>
    </div></div></div>
</div>

<?php if ($overCount): ?>
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">
            <?= $overCount === 1 ? 'Bei einem Posten übersteigen' : "Bei $overCount Posten übersteigen" ?> die Sparpläne den verfügbaren Betrag.
        </div>
        <a class="btn btn-sm btn-warning" href="<?= e(url('/wishes/plans')) ?>">Ansehen</a>
    </div>
<?php endif; ?>

<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2">
            <div class="col-12 col-md-4">
                <div class="btn-group w-100" role="group" aria-label="Status">
                    <?php foreach (['open' => 'Offen', 'fulfilled' => 'Erfüllt', 'all' => 'Alle'] as $k => $label): ?>
                        <input type="radio" class="btn-check" name="status" id="st-<?= $k ?>" value="<?= $k ?>" <?= checked($filters['status'] === $k) ?> onchange="this.form.submit()">
                        <label class="btn btn-sm btn-outline-secondary" for="st-<?= $k ?>"><?= $label ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <select name="occasion" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Anlass">
                    <option value="">Alle Anlässe</option>
                    <?php foreach ($occasions as $o): ?><option <?= selected($o, $filters['occasion']) ?>><?= e($o) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="for_user_id" class="form-select form-select-sm" onchange="this.form.submit()" aria-label="Für wen">
                    <option value="">Alle Personen</option>
                    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= selected($u['id'], $filters['for_user_id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-10 col-md-3">
                <input class="form-control form-control-sm" name="q" value="<?= e($filters['q']) ?>" placeholder="Suchen …" onchange="this.form.submit()">
            </div>
            <div class="col-2 col-md-1 d-grid">
                <?php if ($hasFilter): ?>
                    <a href="<?= e(url('/wishes')) ?>" class="btn btn-sm btn-outline-secondary" title="Filter zurücksetzen"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (!$wishes): ?>
    <div class="card empty-state">
        <i class="bi bi-gift"></i>
        <?php if ($hasFilter): ?>
            <p>Keine Wünsche für diese Auswahl.</p>
            <a class="btn btn-outline-secondary" href="<?= e(url('/wishes')) ?>">Alle offenen Wünsche zeigen</a>
        <?php else: ?>
            <p>Noch keine Wünsche. Halte geplante Anschaffungen oder Weihnachtswünsche mit Bild fest – auf Wunsch mit Sparplan,
                der zeigt, ab wann ihr es euch leisten könnt.</p>
            <a class="btn btn-primary" href="<?= e(url('/wishes/new')) ?>"><i class="bi bi-plus-lg"></i> Ersten Wunsch anlegen</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="row g-3">
    <?php foreach ($wishes as $w): ?>
        <div class="col-sm-6 col-xl-4">
            <a class="card h-100 text-decoration-none text-body wish-card <?= $w['status'] === 'fulfilled' ? 'opacity-75' : '' ?>" href="<?= e(url("/wishes/{$w['id']}")) ?>">
                <?php if ($w['image_path']): ?>
                    <img class="card-img-top wish-img" src="<?= e(url("/wishes/{$w['id']}/image")) ?>" alt="" loading="lazy">
                <?php else: ?>
                    <div class="card-img-top wish-img wish-img-empty"><i class="bi bi-gift"></i></div>
                <?php endif; ?>
                <div class="card-body">
                    <div class="d-flex justify-content-between gap-2">
                        <div class="fw-semibold"><?= e($w['title']) ?></div>
                        <?php if ($w['price'] !== null): ?><div class="amount text-nowrap"><?= money($w['price']) ?></div><?php endif; ?>
                    </div>
                    <div class="small text-body-secondary">
                        <?= e(implode(' · ', array_filter([
                            $w['occasion'],
                            $w['for_user_name'] ? 'für ' . $w['for_user_name'] : null,
                            $w['wanted_by'] ? 'bis ' . date_de($w['wanted_by']) : null,
                        ]))) ?>
                        <?php if ($w['status'] === 'fulfilled'): ?><span class="badge text-bg-success">erfüllt <?= e(date_de($w['fulfilled_at'])) ?></span><?php endif; ?>
                    </div>
                    <?= View::partial('wishes/plan_badges', ['w' => $w]) ?>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
