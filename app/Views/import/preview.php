<?php
use App\Services\CsvImportService;

$counts = ['new' => 0, 'match' => 0, 'transfer' => 0, 'duplicate' => 0, 'pending' => 0];
foreach ($records as $r) {
    $counts[$r['status']]++;
}
$catList = array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['full_name'], 'type' => $c['type']], $categories);
$json = array_map(fn ($r) => [
    'hash' => $r['hash'], 'date' => date_de($r['date']), 'amount' => (float) $r['amount'], 'payee' => $r['payee'],
    'purpose' => mb_strimwidth($r['purpose'], 0, 120, '…'), 'status' => $r['status'], 'action' => $r['default_action'],
    'category' => $r['category_id'] ? (string) $r['category_id'] : '', 'suggestion' => $r['suggestion'],
    'match' => $r['match'] ? date_de($r['match']['booking_date']) . ' · ' . ($r['match']['purchase_store'] ? 'Einkauf ' . $r['match']['purchase_store'] : ($r['match']['transfer_group'] ? 'Umbuchung' : ($r['match']['payee'] ?: 'Buchung'))) : null,
    'transfer' => $r['transfer'] ? ['account' => $r['transfer']['account_name'],
        'partner' => $r['transfer']['partner'] ? date_de($r['transfer']['partner']['booking_date']) : null] : null,
    'purchase' => $r['purchase'] ? 'Einkauf ' . ($r['purchase']['store'] ?: '') . ' vom ' . date_de($r['purchase']['purchase_date']) : null,
    'linkPurchase' => true,
    'recurring' => (bool) $r['recurring_id'], 'interval' => '', 'edit' => false, 'manual' => false,
    'rule' => null, 'ruleSaved' => '', 'rawPayee' => (string) $r['payee'], 'rawPurpose' => (string) $r['purpose'],
], $records);
$intervals = array_map(fn ($i) => ['id' => $i, 'name' => interval_label($i)], array_keys(\App\Services\RecurrenceService::STEPS));
$canRule = !\App\Core\Auth::isChild();
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
        <form method="post" action="<?= e(url('/import/commit')) ?>" x-data="importPreview(<?= e(json_encode($json)) ?>, <?= e(json_encode($catList)) ?>, <?= e(json_encode($intervals)) ?>, <?= $canRule ? 'true' : 'false' ?>)">
            <?= csrf_field() ?>
            <div class="d-flex flex-wrap gap-2 mb-3 small">
                <span class="badge rounded-pill text-bg-primary"><?= $counts['new'] ?> neu</span>
                <?php if ($counts['match']): ?><span class="badge rounded-pill text-bg-info"><?= $counts['match'] ?> passend zu vorhandenen Buchungen</span><?php endif; ?>
                <?php if ($counts['transfer']): ?><span class="badge rounded-pill text-bg-secondary"><i class="bi bi-arrow-left-right"></i> <?= $counts['transfer'] ?> Umbuchungen zwischen eigenen Konten</span><?php endif; ?>
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
                            <input type="hidden" :name="'recurring[' + r.hash + ']'" :value="r.interval">
                            <input type="hidden" :name="'purchase[' + r.hash + ']'" :value="r.linkPurchase ? '1' : '0'">
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
                                    <template x-if="r.transfer && r.action === 'transfer'">
                                        <span class="badge text-bg-secondary"><i class="bi bi-arrow-left-right"></i>
                                            <span x-text="'Umbuchung ' + (r.amount < 0 ? 'an ' : 'von ') + r.transfer.account + (r.transfer.partner ? ' · Gegenbuchung vom ' + r.transfer.partner + ' wird verknüpft' : ' · Gegenbuchung wird angelegt')"></span>
                                        </span>
                                    </template>
                                    <template x-if="r.recurring"><span class="badge text-bg-light border"><i class="bi bi-arrow-repeat"></i> Dauerauftrag</span></template>
                                    <template x-if="r.purchase && r.action === 'import'">
                                        <button type="button" class="badge border" :class="r.linkPurchase ? 'text-bg-info' : 'text-bg-light text-decoration-line-through'"
                                                @click="r.linkPurchase = !r.linkPurchase" :title="r.linkPurchase ? 'Wird mit diesem Einkauf verknüpft – antippen zum Aufheben' : 'Nicht verknüpfen'">
                                            <i class="bi bi-receipt"></i> <span x-text="r.purchase"></span>
                                        </button>
                                    </template>
                                    <template x-if="r.status !== 'duplicate' && r.action !== 'transfer' && !r.edit">
                                        <button type="button" class="badge border" :class="r.category ? (r.suggestion ? 'text-bg-warning' : 'text-bg-light') : 'text-bg-danger-subtle'"
                                                @click="r.edit = true" x-text="r.category ? catName(r.category) : 'Kategorie wählen'"></button>
                                    </template>
                                    <template x-if="r.edit">
                                        <select class="form-select form-select-sm w-auto" x-model="r.category" @change="chooseCategory(r)" x-init="$el.focus()">
                                            <option value="">– ohne –</option>
                                            <template x-for="c in cats.filter(c => c.type === (r.amount < 0 ? 'expense' : 'income'))" :key="c.id">
                                                <option :value="String(c.id)" x-text="c.name" :selected="String(c.id) === r.category"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="canRule && r.manual && r.category && !r.rule && r.status !== 'duplicate'">
                                        <button type="button" class="btn btn-link btn-sm p-0 small" @click="openRule(r)"><i class="bi bi-magic"></i> Als Regel merken</button>
                                    </template>
                                    <template x-if="canRecurring(r) && !r.interval">
                                        <button type="button" class="btn btn-link btn-sm p-0 small text-body-secondary" @click="r.interval = 'monthly'"><i class="bi bi-arrow-repeat"></i> Zu Fixkosten</button>
                                    </template>
                                    <template x-if="canRecurring(r) && r.interval">
                                        <span class="d-inline-flex align-items-center gap-1">
                                            <span class="badge text-bg-success"><i class="bi bi-arrow-repeat"></i> Fixkosten</span>
                                            <select class="form-select form-select-sm w-auto py-0" x-model="r.interval" aria-label="Intervall">
                                                <template x-for="iv in intervals" :key="iv.id"><option :value="iv.id" x-text="iv.name" :selected="iv.id === r.interval"></option></template>
                                            </select>
                                            <button type="button" class="btn btn-sm btn-link p-0 text-body-secondary" @click="r.interval = ''" aria-label="Nicht als Fixkosten"><i class="bi bi-x-lg"></i></button>
                                        </span>
                                    </template>
                                </div>
                                <!-- Kategorie-Zuweisung als Regel speichern -->
                                <template x-if="r.rule">
                                    <div class="border rounded p-2 mt-2 bg-body-tertiary">
                                        <div class="small mb-1">Künftig automatisch <strong x-text="catName(r.category)"></strong> zuordnen, wenn …</div>
                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                            <select class="form-select form-select-sm w-auto" x-model="r.rule.field" @change="r.rule.value = ruleValue(r, r.rule.field)">
                                                <option value="payee">Empfänger</option>
                                                <option value="purpose">Verwendungszweck</option>
                                                <option value="any">Empfänger oder Zweck</option>
                                            </select>
                                            <select class="form-select form-select-sm w-auto" x-model="r.rule.operator">
                                                <option value="contains">enthält</option>
                                                <option value="starts">beginnt mit</option>
                                                <option value="equals">ist genau</option>
                                            </select>
                                            <input class="form-control form-control-sm flex-grow-1" style="min-width: 10rem" x-model="r.rule.value" @keydown.enter.prevent="saveRule(r)">
                                            <button type="button" class="btn btn-sm btn-primary" @click="saveRule(r)" :disabled="r.rule.busy"><i class="bi bi-check-lg"></i> Regel speichern</button>
                                            <button type="button" class="btn btn-sm btn-link" @click="r.rule = null">Abbrechen</button>
                                        </div>
                                        <div class="small text-danger mt-1" x-show="r.rule.error" x-text="r.rule.error"></div>
                                    </div>
                                </template>
                                <div class="small text-success mt-1" x-show="r.ruleSaved" x-text="r.ruleSaved"></div>
                            </div>
                            <div class="text-end">
                                <div class="amount" :class="r.amount < 0 ? 'text-danger' : 'text-success'" x-text="HB.money(r.amount)"></div>
                                <template x-if="r.status !== 'duplicate'">
                                    <select class="form-select form-select-sm mt-1" x-model="r.action" style="width: 9rem">
                                        <option value="import">Importieren</option>
                                        <option value="link" x-show="r.status === 'match'">Zusammenführen</option>
                                        <option value="transfer" x-show="r.transfer">Als Umbuchung</option>
                                        <option value="skip">Überspringen</option>
                                    </select>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <p class="small text-body-secondary mt-2"><span class="badge text-bg-warning">gelb</span> = automatischer Kategorie-Vorschlag. „Zusammenführen“ ergänzt eine vorhandene Buchung (z. B. aus einem Dauerauftrag oder einem erfassten Einkauf) statt eine doppelte anzulegen.
                „Als Umbuchung“ erscheint bei Überweisungen zwischen eigenen Konten (erkannt an der IBAN oder an der gegenläufigen Buchung auf dem anderen Konto) – eine schon vorhandene Gegenbuchung wird verknüpft, sonst angelegt.
                Eine gewählte Kategorie lässt sich „als Regel merken“ – die Regel gilt sofort für passende Zeilen. „Zu Fixkosten“ legt beim Übernehmen eine wiederkehrende Buchung an.</p>
            <div class="sticky-actions d-flex gap-2 align-items-center">
                <button class="btn btn-primary btn-lg"><i class="bi bi-check-lg"></i> <span x-text="countAction('import') + countAction('link') + countAction('transfer')"></span> Buchungen übernehmen</button>
                <a href="<?= e(url('/import')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
    function importPreview(rows, cats, intervals, canRule) {
        const names = {};
        cats.forEach(c => names[String(c.id)] = c.name);
        return {
            rows, cats, intervals, canRule,
            catName(id) { return names[String(id)] || '?'; },
            countAction(a) { return this.rows.filter(r => r.status !== 'duplicate' && r.action === a).length; },
            withoutCategory() { return this.rows.filter(r => r.action === 'import' && !r.category).length; },
            canRecurring(r) { return r.status !== 'duplicate' && r.action === 'import' && !r.recurring; },
            chooseCategory(r) {
                r.edit = false;
                r.suggestion = null;
                r.manual = true;
                r.ruleSaved = '';
            },
            ruleValue(r, field) {
                const v = field === 'purpose' ? r.rawPurpose : (r.rawPayee || r.rawPurpose);
                return (v || '').trim().slice(0, 60);
            },
            openRule(r) {
                const field = r.rawPayee ? 'payee' : 'purpose';
                r.rule = { field, operator: 'contains', value: this.ruleValue(r, field), busy: false, error: '' };
            },
            async saveRule(r) {
                r.rule.busy = true;
                r.rule.error = '';
                try {
                    const res = await HB.post('/import/rule', {
                        field: r.rule.field, operator: r.rule.operator, value: r.rule.value, category_id: r.category,
                    });
                    // Regel auf passende Zeilen anwenden – bewusst gewählte Kategorien bleiben unangetastet
                    const hit = new Set(res.hashes);
                    let n = 0;
                    this.rows.forEach(o => {
                        if (o === r || !hit.has(o.hash) || o.status === 'duplicate' || o.manual) return;
                        if ((o.amount < 0) !== (r.amount < 0)) return;
                        o.category = res.category;
                        o.suggestion = 'rule';
                        n++;
                    });
                    r.rule = null;
                    r.ruleSaved = 'Regel gespeichert' + (n ? ' und auf ' + n + (n === 1 ? ' weitere Zeile' : ' weitere Zeilen') + ' angewendet.' : '.');
                } catch (e) {
                    r.rule.error = e.message;
                    r.rule.busy = false;
                }
            },
        };
    }
</script>
