<?php $isEdit = !empty($u['id']); ?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($isEdit ? url("/users/{$u['id']}") : url('/users')) ?>" class="card" x-data="{ role: '<?= e($u['role']) ?>' }">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="<?= e($u['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="email">E-Mail (Anmeldename)</label>
                    <input type="email" class="form-control" id="email" name="email" value="<?= e($u['email']) ?>" autocomplete="off" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password"><?= $isEdit ? 'Neues Passwort (leer lassen = unverändert)' : 'Passwort' ?></label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" <?= $isEdit ? '' : 'required' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label">Rolle</label>
                    <div class="btn-group w-100" role="group">
                        <?php foreach (['admin' => 'Administrator', 'member' => 'Mitglied', 'child' => 'Kind'] as $k => $l): ?>
                            <input type="radio" class="btn-check" name="role" id="role_<?= $k ?>" value="<?= $k ?>" x-model="role" <?= checked($u['role'] === $k) ?>>
                            <label class="btn btn-outline-primary" for="role_<?= $k ?>"><?= e($l) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($isEdit): ?>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= checked($u['active'] ?? 1) ?>>
                        <label class="form-check-label" for="active">Aktiv (darf sich anmelden)</label>
                    </div>
                <?php endif; ?>

                <div x-show="role !== 'admin'">
                    <h2 class="h6 mt-3">Konten</h2>
                    <?php if (!$perms): ?><p class="small text-body-secondary">Noch keine Konten angelegt.</p><?php endif; ?>
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Konto</th><th class="text-center">Sehen</th><th class="text-center">Buchen</th></tr></thead>
                        <tbody>
                        <?php foreach ($perms as $aid => $p): ?>
                            <tr>
                                <td><?= e($p['name']) ?></td>
                                <td class="text-center"><input class="form-check-input" type="checkbox" name="perm_view[<?= (int) $aid ?>]" value="1" <?= checked($p['can_view']) ?>></td>
                                <td class="text-center"><input class="form-check-input" type="checkbox" name="perm_book[<?= (int) $aid ?>]" value="1" <?= checked($p['can_book']) ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-body-secondary" x-show="role === 'admin'">Administratoren haben Zugriff auf alle Konten.</p>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary">Speichern</button>
                <a href="<?= e(url('/users')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
