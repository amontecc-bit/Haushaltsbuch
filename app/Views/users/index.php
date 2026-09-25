<div class="card">
    <div class="list-group list-group-flush tx-list">
        <?php foreach ($users as $u): ?>
            <a class="list-group-item list-group-item-action <?= $u['active'] ? '' : 'opacity-50' ?>" href="<?= e(url("/users/{$u['id']}/edit")) ?>">
                <span class="cat-dot" style="background: <?= $u['role'] === 'admin' ? '#0d6efd' : ($u['role'] === 'child' ? '#fd7e14' : '#198754') ?>"><i class="bi bi-person"></i></span>
                <div class="tx-main">
                    <div class="tx-title"><?= e($u['name']) ?></div>
                    <div class="tx-sub"><?= e($u['email']) ?> · <?= e(role_label($u['role'])) ?><?= $u['active'] ? '' : ' · deaktiviert' ?></div>
                </div>
                <div class="small text-body-secondary d-none d-sm-block"><?= $u['last_login_at'] ? 'zuletzt ' . e(date_de($u['last_login_at'])) : 'noch nie angemeldet' ?></div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<div class="small text-body-secondary mt-3">
    <strong>Rollen:</strong> Administratoren sehen alles und verwalten Konten, Kategorien und Personen.
    Mitglieder und Kinder sehen nur die Konten, die für sie freigegeben sind. Kinder dürfen außerdem keine Regeln ändern.
</div>
