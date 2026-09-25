<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="h5">Ersteinrichtung</h2>
        <p class="text-body-secondary small">Lege deinen Haushalt und dein Administrator-Konto an. Weitere Familienmitglieder kannst du danach hinzufügen.</p>
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url('/setup')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="household">Name des Haushalts</label>
                <input class="form-control" id="household" name="household" value="<?= e($old['household'] ?? '') ?>" placeholder="z. B. Familie Müller" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="name">Dein Name</label>
                <input class="form-control" id="name" name="name" value="<?= e($old['name'] ?? '') ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label" for="email">E-Mail (Anmeldename)</label>
                <input type="email" class="form-control" id="email" name="email" value="<?= e($old['email'] ?? '') ?>" autocomplete="username" required>
            </div>
            <div class="row g-2 mb-4">
                <div class="col-sm-6">
                    <label class="form-label" for="password">Passwort</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" autocomplete="new-password" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label" for="password_confirm">Wiederholen</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" minlength="8" autocomplete="new-password" required>
                </div>
            </div>
            <button class="btn btn-primary btn-lg w-100">Haushalt anlegen</button>
        </form>
    </div>
</div>
