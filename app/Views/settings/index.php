<div class="row g-3">
    <div class="col-lg-7">
        <?php if ($isAdmin): ?>
            <form method="post" action="<?= e(url('/settings')) ?>" class="card mb-3">
                <?= csrf_field() ?>
                <div class="card-header bg-transparent"><strong>Haushalt</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="household_name">Name</label>
                        <input class="form-control" id="household_name" name="household_name" value="<?= e($household['name']) ?>">
                    </div>

                    <h3 class="h6 mt-4">Kassenbon- und PDF-Erkennung</h3>
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="ocr_mode" id="ocr_local" value="local" <?= checked($settings['ocr_mode'] !== 'ai') ?>>
                            <label class="form-check-label" for="ocr_local"><strong>Lokal im Browser</strong> – kostenlos, Bilder verlassen das Gerät nicht, aber ungenauer.</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="ocr_mode" id="ocr_ai" value="ai" <?= checked($settings['ocr_mode'] === 'ai') ?>>
                            <label class="form-check-label" for="ocr_ai"><strong>KI (Claude)</strong> – sehr genau inkl. Kategorievorschlägen, benötigt API-Schlüssel, wenige Cent pro Bon.</label>
                        </div>
                        <div class="form-text">Bei jedem Einlesen kann trotzdem zwischen beiden Verfahren gewechselt werden.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ai_api_key">Anthropic API-Schlüssel</label>
                        <input type="password" class="form-control font-monospace" id="ai_api_key" name="ai_api_key" autocomplete="off"
                               placeholder="<?= $settings['ai_api_key'] !== '' ? '•••••••• gespeichert – leer lassen, um ihn zu behalten' : ($envKey ? 'aus .env-Datei – optional hier überschreiben' : 'sk-ant-…') ?>">
                        <?php if ($settings['ai_api_key'] !== ''): ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" id="ai_key_remove" name="ai_key_remove" value="1">
                                <label class="form-check-label small" for="ai_key_remove">Gespeicherten Schlüssel entfernen</label>
                            </div>
                        <?php endif; ?>
                        <div class="form-text">Erhältlich unter console.anthropic.com. Der Schlüssel wird nur serverseitig verwendet.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="ai_model">Modell</label>
                        <input class="form-control font-monospace" id="ai_model" name="ai_model" value="<?= e($settings['ai_model']) ?>" placeholder="<?= e($defaultModel) ?>">
                    </div>

                    <h3 class="h6 mt-4">Prognose</h3>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" for="forecast_months">Zeitraum (Monate)</label>
                            <input type="number" class="form-control" id="forecast_months" name="forecast_months" min="1" max="60" value="<?= e($settings['forecast_months']) ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="forecast_avg_months">Durchschnitt aus (Monaten)</label>
                            <input type="number" class="form-control" id="forecast_avg_months" name="forecast_avg_months" min="1" max="24" value="<?= e($settings['forecast_avg_months']) ?>">
                        </div>
                    </div>
                </div>
                <div class="card-footer"><button class="btn btn-primary">Speichern</button></div>
            </form>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <?php if ($bookable): ?>
            <form method="post" action="<?= e(url('/settings/preferences')) ?>" class="card mb-3">
                <?= csrf_field() ?>
                <div class="card-header bg-transparent"><strong>Meine Vorzugskonten</strong></div>
                <div class="card-body">
                    <p class="small text-body-secondary">Diese Konten sind bei den folgenden Aktionen vorausgewählt.</p>
                    <?php foreach ($prefLabels as $key => $label): ?>
                        <div class="mb-2">
                            <label class="form-label" for="pref_<?= e($key) ?>"><?= e($label) ?></label>
                            <select class="form-select" id="pref_<?= e($key) ?>" name="<?= e($key) ?>">
                                <option value="">– keine Vorgabe –</option>
                                <?php foreach ($bookable as $a): ?>
                                    <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $prefs[$key] ?? null) ?>><?= e($a['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                    <div class="form-text">Ohne Vorgabe: beim Einkauf das zuletzt benutzte Konto, sonst das erste Konto der Liste.</div>
                </div>
                <div class="card-footer"><button class="btn btn-outline-primary">Speichern</button></div>
            </form>
        <?php endif; ?>

        <form method="post" action="<?= e(url('/settings/password')) ?>" class="card mb-3">
            <?= csrf_field() ?>
            <div class="card-header bg-transparent"><strong>Mein Passwort ändern</strong></div>
            <div class="card-body">
                <input type="text" name="username" value="<?= e($user['email']) ?>" autocomplete="username" class="d-none" readonly>
                <div class="mb-2"><input type="password" class="form-control" name="current" placeholder="Aktuelles Passwort" autocomplete="current-password" required></div>
                <div class="mb-2"><input type="password" class="form-control" name="new" placeholder="Neues Passwort (mind. 8 Zeichen)" minlength="8" autocomplete="new-password" required></div>
                <div class="mb-2"><input type="password" class="form-control" name="confirm" placeholder="Neues Passwort wiederholen" minlength="8" autocomplete="new-password" required></div>
            </div>
            <div class="card-footer"><button class="btn btn-outline-primary">Passwort ändern</button></div>
        </form>

        <div class="card">
            <div class="card-body d-flex gap-2 align-items-center">
                <i class="bi bi-person-circle fs-3"></i>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($user['name']) ?></div>
                    <div class="small text-body-secondary"><?= e($user['email']) ?> · <?= e(role_label($user['role'])) ?></div>
                </div>
                <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?><button class="btn btn-outline-secondary btn-sm">Abmelden</button></form>
            </div>
        </div>
    </div>
</div>
