<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header bg-transparent"><strong>Claude auf dem Smartphone</strong></div>
            <div class="card-body">
                <p>Mit dem Claude-Zugang kannst du per Chat oder Sprache z. B. „Setz Milch auf die Liste“, „Was kann ich heute kochen?“
                    oder „12,50 bar beim Bäcker“ erledigen.</p>
                <p class="mb-2">Erlaubt sind nur:</p>
                <ul>
                    <li>Einkaufslisten ansehen und bearbeiten</li>
                    <li>Vorrat ansehen und korrigieren</li>
                    <li>Rezepte ansehen, anlegen, als gekocht verbuchen und fehlende Zutaten auf die Liste setzen</li>
                    <li>Virtuelle Posten anlegen, ändern, zusammenführen und echte Produkte zuordnen (Produkte nur mit Namen, ohne Preise)</li>
                    <li>Bargeld-Ausgaben buchen (nur auf Bargeld-Konten, für die man Buchungsrechte hat)</li>
                </ul>
                <div class="alert alert-info small"><i class="bi bi-shield-lock"></i> Kontostände, Umsätze, Auswertungen, Preise und Kredite
                    sind über Claude nicht abrufbar. Was Claude ändert, steht unten im Protokoll.</div>

                <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url('/settings/claude')) ?>" class="d-flex flex-wrap gap-2 align-items-center mb-3">
                        <?= csrf_field() ?>
                        <?php if ($enabled): ?>
                            <span class="badge text-bg-success"><i class="bi bi-check-circle"></i> eingeschaltet</span>
                            <button class="btn btn-outline-danger btn-sm" name="enabled" value="0">Ausschalten</button>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">ausgeschaltet</span>
                            <button class="btn btn-primary" name="enabled" value="1">Für den Haushalt einschalten</button>
                        <?php endif; ?>
                    </form>
                <?php elseif (!$enabled): ?>
                    <div class="alert alert-warning small">Der Zugang ist ausgeschaltet. Ein Administrator kann ihn hier einschalten.</div>
                <?php endif; ?>

                <?php if ($enabled): ?>
                    <h3 class="h6 mt-4">So verbindest du Claude</h3>
                    <ol class="small">
                        <li>Auf <strong>claude.ai</strong> (im Browser) unter <em>Einstellungen › Konnektoren</em> „Benutzerdefinierten Konnektor hinzufügen“ wählen.</li>
                        <li>Name „Haushaltsbuch“ und diese Adresse eintragen:
                            <div class="input-group input-group-sm my-1">
                                <input class="form-control font-monospace" value="<?= e($mcpUrl) ?>" readonly id="mcpUrl">
                                <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('mcpUrl').value)"><i class="bi bi-clipboard"></i></button>
                            </div>
                        </li>
                        <li>„Verbinden“ – es öffnet sich das Haushaltsbuch: anmelden und den Zugriff erlauben.</li>
                        <li>Fertig: In der Claude-App auf dem Smartphone steht der Konnektor automatisch zur Verfügung.</li>
                    </ol>
                    <?php if (!str_starts_with($mcpUrl, 'https://')): ?>
                        <div class="alert alert-warning small mb-0">Claude verbindet sich nur über HTTPS mit einer öffentlich erreichbaren Adresse –
                            diese Installation läuft ohne HTTPS (z. B. lokal unter XAMPP).</div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header bg-transparent"><strong><?= $isAdmin ? 'Verbundene Apps im Haushalt' : 'Meine verbundenen Apps' ?></strong></div>
            <?php if (!$connections): ?>
                <div class="card-body text-body-secondary small">Noch keine Verbindung.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($connections as $c): ?>
                        <li class="list-group-item d-flex gap-2 align-items-center">
                            <i class="bi bi-stars text-primary fs-5"></i>
                            <div class="flex-grow-1 small">
                                <div class="fw-semibold"><?= e($c['client_name']) ?><?= $isAdmin ? ' · ' . e($c['user_name']) : '' ?></div>
                                <div class="text-body-secondary">verbunden <?= e(date_de($c['created_at'])) ?>
                                    <?= $c['last_used_at'] ? ' · zuletzt ' . e(date_de($c['last_used_at'])) . ' ' . e(substr($c['last_used_at'], 11, 5)) : '' ?></div>
                            </div>
                            <form method="post" action="<?= e(url("/settings/claude/{$c['id']}/revoke")) ?>" onsubmit="return confirm('Verbindung trennen?')">
                                <?= csrf_field() ?>
                                <button class="btn btn-outline-danger btn-sm">Trennen</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header bg-transparent"><strong>Protokoll</strong> <span class="small text-body-secondary">– Änderungen über Claude</span></div>
            <?php if (!$log): ?>
                <div class="card-body text-body-secondary small">Noch keine Änderungen.</div>
            <?php else: ?>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($log as $l): ?>
                        <li class="list-group-item">
                            <div><?= e($l['summary']) ?></div>
                            <div class="text-body-secondary"><?= e(date_de($l['created_at'])) ?> <?= e(substr($l['created_at'], 11, 5)) ?> · <?= e($l['user_name'] ?? '–') ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
