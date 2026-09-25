<div class="card shadow-sm">
    <div class="card-body p-4">
        <form method="post" action="<?= e(url('/login')) ?>">
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="email">E-Mail</label>
                <input type="email" class="form-control form-control-lg" id="email" name="email" value="<?= e($email) ?>" autocomplete="username" required autofocus>
            </div>
            <div class="mb-4">
                <label class="form-label" for="password">Passwort</label>
                <input type="password" class="form-control form-control-lg" id="password" name="password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-primary btn-lg w-100">Anmelden</button>
        </form>
    </div>
</div>
