<?php
use App\Services\CsvImportService;

$counts = ['new' => 0, 'match' => 0, 'duplicate' => 0, 'pending' => 0];
foreach ($records as $r) {
    $counts[$r['status']]++;
}
$catList = array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['full_name'], 'type' => $c['type']], $categories);
$json = array_map(fn ($r) => [
    'hash' => $r['hash'], 'date' => date_de($r['date']), 'amount' => (float) $r['amount'], 'payee' => $r['payee'],
    'purpose' => mb_strimwidth($r['purpose'], 0, 120, '…'), 'status' => $r['status'], 'action' => $r['default_action'],
    'category' => $r['category_id'] ? (string) $r['category_id'] : '', 'suggestion' => $r['suggestion'],
    'match' => $r['match'] ? date_de($r['match']['booking_date']) . ' · ' . ($r['match']['payee'] ?: 'Buchung') : null,
    'recurring' => (bool) $r['recurring_id'], 'edit' => false,
], $records);
$headerCells = array_values(array_filter($headerRow, fn ($c) => $c !== ''));
?>
<div class="mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="badge text-bg-light border fs-6"><i class="bi bi-file-earmark-text"></i> <?= e($state['name']) ?></span>
        <span class="badge text-bg-light border fs-6"><i class="bi bi-wallet2"></i> <?= e($account['name']) ?></span>
        <span class="badge text-bg-light border fs-6">Format: <?= e($profile['name'] ?? ($mapping ? 'eigene Zuordnung' : 'unbekannt')) ?></span>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#mapping"><i class="bi bi-sliders"></i> Spalten zuordnen</button>
    </div>
</div>

<?php foreach ($warnings as $w): ?><div class="alert alert-warning"><?= e($w) ?></div><?php endforeach; ?>

<!-- Spaltenzuordnung -->
<div class="collapse <?= $header ? '' : 'show' ?> mb-3" id="mapping">
    <div class="card">
        <div class="card-body">
            <?php if (!$header): ?>
                <div class="alert alert-info">Das Format wurde nicht erkannt. Bitte ordne die Spalten einmal zu – mit einem Profilnamen wird die Zuordnung für künftige Importe gespeichert.</div>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/import/preview')) ?>" class="mb-3 d-flex gap-2 align-items-end">
                <?= csrf_field() ?>
                <div class="flex-grow-1">
                    <label class="form-label">Vorhandenes Profil verwenden</label>
                    <select class="form-select" name="profile_id">
                        <?php foreach ($profiles as $p): ?><option value="<?= (int) $p['id'] ?>" <?= selected($p['id'], $profile['id'] ?? null) ?>><?= e($p['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-outline-primary">Anwenden</button>
            </form>

            <form method="post" action="<?= e(url('/import/preview')) ?>">
                <?= csrf_field() ?>
                <div class="row g-2">
                    <div class="col-6 col-md-3">
                        <label class="form-label">Trennzeichen</label>
                        <select class="form-select" name="delimiter">
                            <?php foreach ([';' => 'Semikolon ;', ',' => 'Komma ,', 'tab' => 'Tabulator', '|' => 'Senkrechter Strich |'] as $k => $l): ?>
                                <option value="<?= e($k) ?>" <?= selected($k === 'tab' ? "\t" : $k, $delimiter) ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">Dezimalzeichen</label>
                        <select class="form-select" name="decimal_sep">
                            <option value="," <?= selected(',', $decimalSep) ?>>Komma (1.234,56)</option>
                            <option value="." <?= selected('.', $decimalSep) ?>>Punkt (1,234.56)</option>
                        </select>
                    </div>
                    <?php foreach (CsvImportService::FIELDS as $field => $label): ?>
                        <div class="col-6 col-md-3">
                            <label class="form-label"><?= e($label) ?><?= in_array($field, ['date', 'amount'], true) ? ' *' : '' ?></label>
                            <select class="form-select form-select-sm" name="map_<?= $field ?>">
                                <option value="">–</option>
                                <?php foreach ($headerCells as $cell): ?>
                                    <option value="<?= e($cell) ?>" <?= selected(mb_strtolower($cell), mb_strtolower($mapping[$field] ?? '')) ?>><?= e($cell) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (!$headerCells || count($headerCells) < 2): ?>
                    <p class="small text-danger mt-2">Keine Kopfzeile gefunden. Stimmt das Trennzeichen?</p>
                <?php endif; ?>
                <div class="row g-2 mt-2 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Als Profil speichern unter (optional)</label>
                        <input class="form-control" name="profile_name" placeholder="z. B. Meine Bank">
                    </div>
                    <div class="col-md-6"><button class="btn btn-primary">Zuordnung übernehmen</button></div>
                </div>
            </form>

            <details class="mt-3">
                <summary class="small text-body-secondary">Rohdaten der Datei anzeigen</summary>
                <div class="table-responsive mt-2" style="max-height: 300px">
                    <table class="table table-sm small">
                        <?php foreach ($sampleRows as $row): ?>
                            <tr><?php foreach ($row as $cell): ?><td class="text-nowrap"><?= e(mb_strimwidth($cell, 0, 40, '…')) ?></td><?php endforeach; ?></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </details>
        </div>
    </div>
</div>

<?php if ($header): ?>
    <?php if (!$records): ?>
        <div class="alert alert-warning">In der Datei wurden keine Buchungen gefunden. Bitte die Spaltenzuordnung prüfen.</div>
    <?php else: ?>
        <form method="post" action="<?= e(url('/import/commit')) ?>" x-data="importPreview(<?= e(json_encode($json)) ?>, <?= e(json_encode($catList)) ?>)">
            <?= csrf_field() ?>
            <div class="d-flex flex-wrap gap-2 mb-3 small">
                <span class="badge rounded-pill text-bg-primary"><?= $counts['new'] ?> neu</span>
                <?php if ($counts['match']): ?><span class="badge rounded-pill text-bg-info"><?= $counts['match'] ?> passend zu vorhandenen Buchungen</span><?php endif; ?>
                <?php if ($counts['duplicate']): ?><span class="badge rounded-pill text-bg-secondary"><?= $counts['duplicate'] ?> bereits importiert</span><?php endif; ?>
                <?php if ($counts['pending']): ?><span class="badge rounded-pill text-bg-warning"><?= $counts['pending'] ?> vorgemerkt (werden übersprungen)</span><?php endif; ?>
                <span class="badge rounded-pill text-bg-light border" x-text="withoutCategory() + ' ohne Kategorie'"></span>
            </div>

            <div class="card">
                <div class="list-group list-group-flush tx-list">
                    <template x-for="(r, i) in rows" :key="r.hash">
                        <div class="list-group-item" :class="r.action === 'skip' && 'opacity-50'">
                            <input type="hidden" :name="'action[' + r.hash + ']'" :value="r.action">
                            <input type="hidden" :name="'category[' + r.hash + ']'" :value="r.category">
                            <div class="tx-main">
                                <div class="tx-title" x-text="r.payee || r.purpose || '–'"></div>
                                <div class="tx-sub">
                                    <span x-text="r.date"></span>
                                    <span x-show="r.payee && r.purpose" x-text="' · ' + r.purpose"></span>
                                </div>
                                <div class="d-flex flex-wrap gap-1 mt-1 align-items-center">
                                    <template x-if="r.status === 'duplicate'"><span class="badge text-bg-secondary">bereits importiert</span></template>
                                    <template x-if="r.status === 'pending'"><span class="badge text-bg-warning">vorgemerkt</span></template>
                                    <template x-if="r.status === 'match'"><span class="badge text-bg-info" x-text="'passt zu: ' + r.match"></span></template>
                                    <template x-if="r.recurring"><span class="badge text-bg-light border"><i class="bi bi-arrow-repeat"></i> Dauerauftrag</span></template>
                                    <template x-if="r.status !== 'duplicate' && !r.edit">
                                        <button type="button" class="badge border" :class="r.category ? (r.suggestion ? 'text-bg-warning' : 'text-bg-light') : 'text-bg-danger-subtle'"
                                                @click="r.edit = true" x-text="r.category ? catName(r.category) : 'Kategorie wählen'"></button>
                                    </template>
                                    <template x-if="r.edit">
                                        <select class="form-select form-select-sm w-auto" x-model="r.category" @change="r.edit = false; r.suggestion = null" x-init="$el.focus()">
                                            <option value="">– ohne –</option>
                                            <template x-for="c in cats.filter(c => c.type === (r.amount < 0 ? 'expense' : 'income'))" :key="c.id">
                                                <option :value="String(c.id)" x-text="c.name" :selected="String(c.id) === r.category"></option>
                                            </template>
                                        </select>
                                    </template>
                                </div>
                            </div>
                            <div class="text-end">
                                <div class="amount" :class="r.amount < 0 ? 'text-danger' : 'text-success'" x-text="HB.money(r.amount)"></div>
                                <template x-if="r.status !== 'duplicate'">
                                    <select class="form-select form-select-sm mt-1" x-model="r.action" style="width: 9rem">
                                        <option value="import">Importieren</option>
                                        <option value="link" x-show="r.status === 'match'">Zusammenführen</option>
                                        <option value="skip">Überspringen</option>
                                    </select>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <p class="small text-body-secondary mt-2"><span class="badge text-bg-warning">gelb</span> = automatischer Kategorie-Vorschlag. „Zusammenführen“ ergänzt eine vorhandene Buchung (z. B. aus einem Dauerauftrag) statt eine doppelte anzulegen.</p>
            <div class="sticky-actions d-flex gap-2 align-items-center">
                <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <span x-text="countAction('import') + countAction('link')"></span> Buchungen übernehmen</button>
                <a href="<?= e(url('/import')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
    function importPreview(rows, cats) {
        const names = {};
        cats.forEach(c => names[String(c.id)] = c.name);
        return {
            rows, cats,
            catName(id) { return names[String(id)] || '?'; },
            countAction(a) { return this.rows.filter(r => r.status !== 'duplicate' && r.action === a).length; },
            withoutCategory() { return this.rows.filter(r => r.action === 'import' && !r.category).length; },
        };
    }
</script>
