<form method="post" action="<?= e(url('/shopping')) ?>" class="card mb-3">
    <?= csrf_field() ?>
    <div class="card-body d-flex gap-2">
        <input class="form-control" name="name" maxlength="120" placeholder="Einkauf <?= e(date_de(date('Y-m-d'))) ?>" aria-label="Name der Liste">
        <button class="btn btn-primary text-nowrap"><i class="bi bi-plus-lg"></i> Neue Liste</button>
    </div>
</form>

<?php if (!$lists): ?>
    <div class="card empty-state">
        <i class="bi bi-card-checklist"></i>
        <p>Noch keine Einkaufsliste. Lege oben eine an und wähle die Posten aus deinen bisherigen Einkäufen oder gib neue ein –
            zu jedem Posten siehst du den bisher günstigsten und teuersten Preis.</p>
    </div>
<?php else: ?>
    <div class="card">
        <div class="list-group list-group-flush tx-list">
            <?php foreach ($lists as $l): ?>
                <a class="list-group-item list-group-item-action" href="<?= e(url("/shopping/{$l['id']}")) ?>">
                    <span class="cat-dot" style="background: #2a78d6"><i class="bi bi-card-checklist"></i></span>
                    <div class="tx-main">
                        <div class="tx-title"><?= e($l['name']) ?></div>
                        <div class="tx-sub">
                            <?= e(date_de(substr($l['created_at'], 0, 10))) ?><?= $l['user_name'] ? ' · ' . e($l['user_name']) : '' ?>
                            <?php if ((int) $l['done_count']): ?> · <?= (int) $l['done_count'] ?> erledigt<?php endif; ?>
                        </div>
                    </div>
                    <span class="badge rounded-pill <?= (int) $l['open_count'] ? 'text-bg-primary' : 'text-bg-success' ?>">
                        <?= (int) $l['open_count'] ? (int) $l['open_count'] . ' offen' : 'fertig' ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
