<div class="row g-3">
    <div class="col-lg-7">
        <form method="post" action="<?= e(url('/import/upload')) ?>" enctype="multipart/form-data" class="card" x-data="{ fileName: '' }">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="account_id">In welches Konto?</label>
                    <select class="form-select form-select-lg" id="account_id" name="account_id">
                        <?php foreach ($accounts as $a): ?>
                            <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $selected) ?>><?= e($a['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <label class="dropzone d-block mb-3" :class="fileName && 'border-primary'"
                       @dragover.prevent="$el.classList.add('drag')" @dragleave="$el.classList.remove('drag')"
                       @drop.prevent="$el.classList.remove('drag'); $refs.file.files = $event.dataTransfer.files; fileName = $event.dataTransfer.files[0]?.name">
                    <i class="bi bi-filetype-csv display-5 text-primary"></i>
                    <div class="mt-2" x-show="!fileName">CSV-Datei hierher ziehen oder <u>auswählen</u></div>
                    <div class="mt-2 fw-semibold" x-show="fileName" x-text="fileName" x-cloak></div>
                    <input type="file" name="csv" accept=".csv,.txt,text/csv" class="d-none" x-ref="file" required @change="fileName = $event.target.files[0]?.name">
                </label>
                <div class="mb-3">
                    <label class="form-label" for="profile_id">Format</label>
                    <select class="form-select" id="profile_id" name="profile_id">
                        <option value="">Automatisch erkennen</option>
                        <?php foreach ($profiles as $p): ?>
                            <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?><?= $p['household_id'] ? ' (eigenes)' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-primary btn-lg w-100" :disabled="!fileName">Weiter zur Vorschau</button>
            </div>
        </form>
    </div>
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body small">
                <h2 class="h6">So geht's</h2>
                <ol class="ps-3 mb-0">
                    <li>Im Online-Banking die Umsätze als <strong>CSV</strong> exportieren (bei Sparkasse: „CSV-CAMT-Format“).</li>
                    <li>Datei hier hochladen – das Format wird meist automatisch erkannt.</li>
                    <li>In der Vorschau prüfen: bereits importierte Buchungen werden erkannt und übersprungen,
                        Daueraufträge werden zugeordnet, Kategorien vorgeschlagen.</li>
                </ol>
                <p class="mt-2 mb-0 text-body-secondary">Mitgeliefert: Sparkasse, ING, DKB, Volksbank/Raiffeisen, comdirect, Commerzbank, Postbank, N26. Andere Banken: Spalten einmal zuordnen und als Profil speichern.</p>
            </div>
        </div>
        <?php if ($batches): ?>
            <div class="card">
                <div class="card-header bg-transparent"><strong>Letzte Importe</strong></div>
                <ul class="list-group list-group-flush small">
                    <?php foreach ($batches as $b): ?>
                        <li class="list-group-item d-flex justify-content-between">
                            <span><?= e($b['account_name']) ?> · <?= e(date_de($b['created_at'])) ?><br><span class="text-body-secondary"><?= e($b['filename']) ?></span></span>
                            <span class="text-end"><?= (int) $b['rows_imported'] ?> übernommen<br><span class="text-body-secondary"><?= (int) $b['rows_skipped'] ?> übersprungen</span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</div>
