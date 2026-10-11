<div class="card shadow-sm">
    <div class="card-body p-4">
        <h2 class="h5 mb-3"><i class="bi bi-stars text-primary"></i> „<?= e($client['name']) ?>“ möchte auf dein Haushaltsbuch zugreifen</h2>
        <p class="small text-body-secondary mb-3">
            Angemeldet als <strong><?= e($user['name']) ?></strong>. Rücksprung zu <span class="font-monospace"><?= e($host) ?></span>.
        </p>
        <p class="mb-2">Erlaubt werden nur:</p>
        <ul class="mb-3">
            <li>Einkaufslisten ansehen und bearbeiten</li>
            <li>Vorrat ansehen und korrigieren</li>
            <li>Rezepte ansehen, anlegen und als gekocht verbuchen</li>
            <li>Virtuelle Posten pflegen und Produkte zuordnen (ohne Preise)</li>
            <li>Bargeld-Ausgaben buchen (auf Bargeld-Konten, die du bebuchen darfst)</li>
        </ul>
        <div class="alert alert-info small mb-4">
            <i class="bi bi-shield-lock"></i> Kontostände, Umsätze, Auswertungen, Preise und Kredite sind über diesen Zugang
            nicht abrufbar. Die Verbindung lässt sich jederzeit unter Einstellungen › Claude (MCP) trennen.
        </div>
        <form method="post" action="<?= e(url('/oauth/authorize')) ?>">
            <?= csrf_field() ?>
            <?php foreach ($params as $k => $v): ?>
                <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
            <?php endforeach; ?>
            <div class="d-grid gap-2">
                <button class="btn btn-primary btn-lg" name="decision" value="allow">Erlauben</button>
                <button class="btn btn-outline-secondary" name="decision" value="deny">Ablehnen</button>
            </div>
        </form>
    </div>
</div>
<form method="post" action="<?= e(url('/logout')) ?>" class="text-center mt-3">
    <?= csrf_field() ?>
    <button class="btn btn-link btn-sm">Nicht <?= e($user['name']) ?>? Abmelden</button>
</form>
