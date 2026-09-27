<?php
$isEdit = !empty($purchase['id']);
$catOptions = array_map(fn ($c) => ['id' => (int) $c['id'], 'name' => $c['full_name'], 'parent' => (bool) $c['parent_id']], $categories);
$init = [
    'id'          => $purchase['id'],
    'mode'        => $mode,
    'recognizer'  => ($ocrMode === 'ai' && $aiAvailable) ? 'ai' : 'local',
    'aiAvailable' => $aiAvailable,
    'date'        => $purchase['purchase_date'],
    'store'       => (string) $purchase['store'],
    'accountId'   => $purchase['account_id'] ? (string) $purchase['account_id'] : '',
    'note'        => (string) ($purchase['note'] ?? ''),
    'source'      => $purchase['source'],
    'linked'      => $purchase['transaction_id'] ? ($purchase['tx_payee'] ?? 'Buchung') . ' · ' . date_de($purchase['tx_date'] ?? '') . ' · ' . money($purchase['tx_amount'] ?? 0) : null,
    // Neue Einkäufe: Vorgabe „vom Konto buchen“ (sofern ein buchbares Konto gewählt ist)
    'link'        => $purchase['transaction_id'] ? 'keep' : ($isEdit || !in_array((int) $purchase['account_id'], $bookable, true) ? 'none' : 'new'),
    'totalManual' => $items ? '' : ((float) $purchase['total'] ? money_input($purchase['total']) : ''),
    'items'       => array_map(fn ($i) => [
        'name' => $i['name'], 'quantity' => (float) $i['quantity'], 'unit' => (string) $i['unit'],
        'total' => money_input($i['total_price']), 'category' => $i['category_id'] ? (string) $i['category_id'] : '', 'suggested' => false,
    ], $items),
    'bookable'    => array_map('strval', $bookable),
];
?>
<div x-data="purchaseForm(<?= e(json_encode($init)) ?>, <?= e(json_encode($catOptions)) ?>)" x-init="init()">

    <?php if (!$isEdit): ?>
        <!-- Art der Erfassung -->
        <ul class="nav nav-pills nav-fill mode-tabs mb-3 bg-body rounded-3 p-1 border">
            <li class="nav-item"><button type="button" class="nav-link" :class="mode === 'photo' && 'active'" @click="mode = 'photo'"><i class="bi bi-camera"></i>Foto vom Bon</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="mode === 'pdf' && 'active'" @click="mode = 'pdf'"><i class="bi bi-file-earmark-pdf"></i>PDF</button></li>
            <li class="nav-item"><button type="button" class="nav-link" :class="mode === 'manual' && 'active'" @click="mode = 'manual'"><i class="bi bi-pencil"></i>Von Hand</button></li>
        </ul>

        <!-- Foto / PDF einlesen -->
        <div class="card mb-3" x-show="mode !== 'manual' && !recognized" x-cloak>
            <div class="card-body">
                <template x-if="mode === 'photo'">
                    <div>
                        <div x-show="camera" class="camera-box mb-3">
                            <video x-ref="video" autoplay playsinline muted></video>
                            <div class="camera-controls">
                                <button type="button" class="btn btn-light rounded-circle" @click="stopCamera()" aria-label="Schließen"><i class="bi bi-x-lg"></i></button>
                                <button type="button" class="shutter" @click="snap()" aria-label="Foto aufnehmen"></button>
                                <span style="width: 38px"></span>
                            </div>
                        </div>
                        <div x-show="!camera" class="d-grid gap-2 mb-3">
                            <label class="btn btn-primary btn-lg">
                                <i class="bi bi-camera"></i> Kassenbon fotografieren
                                <input type="file" accept="image/*" capture="environment" class="d-none" @change="addImages($event)">
                            </label>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-primary flex-fill" @click="startCamera()" x-show="hasCamera"><i class="bi bi-webcam"></i> Live-Kamera</button>
                                <label class="btn btn-outline-secondary flex-fill mb-0">
                                    <i class="bi bi-image"></i> Bild auswählen
                                    <input type="file" accept="image/*" multiple class="d-none" @change="addImages($event)">
                                </label>
                            </div>
                        </div>
                        <div class="d-flex gap-2 flex-wrap mb-3" x-show="images.length">
                            <template x-for="(img, i) in images" :key="img.url">
                                <div class="position-relative">
                                    <img :src="img.url" class="rounded border" style="height: 110px">
                                    <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0 m-1 py-0 px-1" @click="removeImage(i)" aria-label="Entfernen"><i class="bi bi-x"></i></button>
                                </div>
                            </template>
                        </div>
                        <div class="small text-body-secondary mb-3" x-show="images.length">Langer Bon? Einfach weitere Fotos hinzufügen – sie werden zusammen ausgewertet.</div>
                    </div>
                </template>

                <template x-if="mode === 'pdf'">
                    <label class="dropzone d-block mb-3" @dragover.prevent @drop.prevent="setPdf($event.dataTransfer.files[0])">
                        <i class="bi bi-file-earmark-pdf display-5 text-danger"></i>
                        <div class="mt-2" x-show="!pdf">Einkaufsliste / Rechnung als PDF wählen</div>
                        <div class="mt-2 fw-semibold" x-show="pdf" x-text="pdf && pdf.name"></div>
                        <input type="file" accept="application/pdf" class="d-none" @change="setPdf($event.target.files[0])">
                    </label>
                </template>

                <div class="d-flex flex-wrap gap-3 align-items-center">
                    <div class="btn-group" role="group" aria-label="Erkennung">
                        <input type="radio" class="btn-check" id="rec_local" value="local" x-model="recognizer">
                        <label class="btn btn-outline-secondary btn-sm" for="rec_local"><i class="bi bi-cpu"></i> Lokal</label>
                        <input type="radio" class="btn-check" id="rec_ai" value="ai" x-model="recognizer" :disabled="!aiAvailable">
                        <label class="btn btn-outline-secondary btn-sm" for="rec_ai" :title="aiAvailable ? '' : 'API-Schlüssel in den Einstellungen hinterlegen'"><i class="bi bi-stars"></i> KI</label>
                    </div>
                    <button type="button" class="btn btn-success btn-lg ms-auto" @click="recognize()" :disabled="busy || (mode === 'photo' ? !images.length : !pdf)">
                        <span x-show="!busy"><i class="bi bi-magic"></i> Erkennen</span>
                        <span x-show="busy" x-cloak><span class="spinner-border spinner-border-sm"></span> <span x-text="status"></span></span>
                    </button>
                </div>
                <div class="progress progress-thin mt-3" x-show="busy && progress > 0" x-cloak><div class="progress-bar" :style="'width:' + progress + '%'"></div></div>
                <div class="small text-body-secondary mt-2" x-show="recognizer === 'local'">Die lokale Erkennung läuft im Browser. Beim ersten Mal werden ca. 5 MB Sprachdaten geladen.</div>
                <div class="alert alert-danger mt-3 mb-0" x-show="error" x-text="error" x-cloak></div>
                <button type="button" class="btn btn-link btn-sm mt-2 px-0" @click="mode = 'manual'">Lieber von Hand erfassen</button>
            </div>
        </div>
    <?php endif; ?>

    <!-- Einkauf -->
    <form @submit.prevent="save()" x-show="mode === 'manual' || recognized" x-cloak>
        <div class="alert alert-info d-flex gap-2 align-items-center py-2" x-show="recognized && !<?= $isEdit ? 'true' : 'false' ?>">
            <i class="bi bi-check2-circle"></i>
            <div class="flex-grow-1 small">
                Erkannt: <strong x-text="stats ? stats.found + ' von ' + stats.rows : items.length"></strong> Posten.
                <span x-show="stats && stats.missing"><span class="badge text-bg-danger" x-text="openCount() + ' ohne Preis'"></span> –
                    diese Zeilen stehen auf dem Bon, waren aber nicht lesbar. Bitte mit dem Bon vergleichen und ergänzen.</span>
                <span x-show="stats && stats.unsure"><span class="badge text-bg-warning" x-text="stats.unsure + ' unsicher'"></span> –
                    <span class="text-warning-emphasis">gelb umrandete</span> Preise sind geraten oder auffällig.</span>
                Bitte kurz prüfen – <span class="badge text-bg-warning">gelb</span> markierte Kategorien sind Vorschläge.
            </div>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="recognized = false">Neu einlesen</button>
        </div>

        <div class="row g-3">
            <div class="col-lg-8 order-2 order-lg-1">
                <div class="card">
                    <div class="card-header bg-transparent d-flex align-items-center">
                        <strong>Posten</strong>
                        <span class="ms-auto small text-body-secondary" x-text="items.length + ' Posten'"></span>
                    </div>
                    <div class="card-body p-2">
                        <template x-for="(it, i) in items" :key="it.key">
                            <div class="item-card" :class="{ suggested: it.suggested, missing: isOpen(it) }">
                                <div class="item-row">
                                    <input class="form-control form-control-sm" placeholder="Artikel" x-model="it.name" list="products" @change="onName(it)" :id="'item-' + it.key">
                                    <input class="form-control form-control-sm text-end" inputmode="decimal" x-model="it.total" @keydown.enter.prevent="addItem(true)" @input="it.corrected = false; it.suspect = ''" :placeholder="it.missing ? 'Preis?' : '0,00'" :class="isOpen(it) ? 'border-danger bg-danger-subtle' : ((it.corrected || it.suspect) && 'border-warning bg-warning-subtle')" :title="it.suspect || (it.corrected ? 'Automatisch korrigiert, damit die Summe zum Beleg passt – bitte prüfen' : '')">
                                </div>
                                <div class="item-row2">
                                    <input class="form-control form-control-sm text-end" x-model="it.quantity" inputmode="decimal" :title="'Menge' + (it.unit ? ' (' + it.unit + ')' : '')">
                                    <select class="form-select form-select-sm" x-model="it.category" @change="it.suggested = false" :class="!it.category && 'text-body-secondary'">
                                        <option value="">Kategorie …</option>
                                        <template x-for="c in cats" :key="c.id">
                                            <option :value="String(c.id)" x-text="(c.parent ? '  ' : '') + c.name" :selected="String(c.id) === it.category"></option>
                                        </template>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-outline-danger" @click="items.splice(i, 1)" aria-label="Entfernen"><i class="bi bi-trash"></i></button>
                                </div>
                                <div class="small text-body-secondary mt-1" x-show="unitPrice(it)" x-text="unitPrice(it)"></div>
                                <div class="small text-body-secondary mt-1 text-truncate" x-show="it.ocr && (isOpen(it) || it.suspect)" :title="it.ocr">
                                    <i class="bi bi-eye"></i> gelesen: <span class="font-monospace" x-text="it.ocr"></span>
                                </div>
                            </div>
                        </template>
                        <datalist id="products">
                            <?php foreach ($products as $p): ?><option value="<?= e($p['name']) ?>"><?php endforeach; ?>
                        </datalist>
                        <button type="button" class="btn btn-outline-primary w-100 mt-1" @click="addItem(true)"><i class="bi bi-plus-lg"></i> Posten hinzufügen</button>
                        <div class="small text-body-secondary mt-2">Tipp: Enter im Preisfeld fügt direkt den nächsten Posten hinzu. Ohne Posten kann auch nur ein Gesamtbetrag erfasst werden.</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 order-1 order-lg-2">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-2 mb-2">
                            <div class="col-6 col-lg-12">
                                <label class="form-label">Datum</label>
                                <input type="date" class="form-control" x-model="date" required>
                            </div>
                            <div class="col-6 col-lg-12">
                                <label class="form-label">Geschäft</label>
                                <input class="form-control" x-model="store" placeholder="z. B. REWE" list="stores">
                                <datalist id="stores"><?php foreach (['REWE', 'EDEKA', 'ALDI', 'Lidl', 'Netto', 'Penny', 'Kaufland', 'dm', 'Rossmann', 'Müller'] as $s): ?><option value="<?= $s ?>"><?php endforeach; ?></datalist>
                            </div>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Bezahlt von Konto</label>
                            <select class="form-select" x-model="accountId" @change="loadCandidates()">
                                <option value="">– kein Konto / bar –</option>
                                <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2" x-show="!items.length">
                            <label class="form-label">Gesamtbetrag</label>
                            <input class="form-control text-end" inputmode="decimal" x-model="totalManual" placeholder="0,00">
                        </div>

                        <div class="d-flex justify-content-between align-items-baseline border-top pt-2 mt-2">
                            <span class="text-body-secondary">Summe</span>
                            <span class="fs-4 amount" x-text="HB.money(total())"></span>
                        </div>
                        <div class="small" x-show="bonTotal !== null" :class="Math.abs(bonTotal - total()) > 0.01 ? 'text-danger fw-semibold' : 'text-success'">
                            <span x-text="'Summe laut Beleg: ' + HB.money(bonTotal)"></span>
                            <span x-show="Math.abs(bonTotal - total()) > 0.01" x-text="' – Differenz ' + HB.money(total() - bonTotal)"></span>
                            <span x-show="Math.abs(bonTotal - total()) <= 0.01"><i class="bi bi-check-lg"></i></span>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label">Mit Kontobuchung verknüpfen</label>
                        <select class="form-select mb-2" x-model="link" @change="loadCandidates()">
                            <option value="keep" x-show="linked">Bestehende Verknüpfung behalten</option>
                            <option value="none">Nicht verknüpfen</option>
                            <option value="existing">Vorhandene Buchung wählen</option>
                            <option value="new" :disabled="!canBook()">Neue Ausgabe auf dem Konto buchen</option>
                        </select>
                        <div class="small text-body-secondary" x-show="link === 'keep'" x-text="linked"></div>
                        <div x-show="link === 'existing'">
                            <select class="form-select form-select-sm" x-model="transactionId">
                                <option value="">– Buchung wählen –</option>
                                <template x-for="c in candidates" :key="c.id">
                                    <option :value="String(c.id)" x-text="(c.exact ? '✓ ' : '') + c.label"></option>
                                </template>
                            </select>
                            <div class="small text-body-secondary mt-1" x-show="!candidates.length">Keine passende Buchung ±7 Tage gefunden (z. B. noch nicht importiert).</div>
                        </div>
                        <div class="small text-body-secondary" x-show="link === 'new'">Es wird eine Ausgabe über die Summe angelegt. Kategorie: größter Anteil der Posten.</div>
                        <div class="alert py-2 px-2 small mt-2 mb-0" x-show="duplicate" x-cloak :class="duplicate && duplicate.same_payee ? 'alert-warning' : 'alert-info'">
                            <template x-if="duplicate">
                                <div>
                                    <div class="fw-semibold"><i class="bi bi-exclamation-triangle"></i> <span x-text="duplicate.same_payee ? 'Diese Ausgabe ist schon gebucht:' : 'Buchung mit gleichem Betrag gefunden:'"></span></div>
                                    <div x-text="duplicate.label"></div>
                                    <div class="mt-1" x-show="link === 'existing' && transactionId === String(duplicate.id)">Der Einkauf wird mit dieser Buchung verknüpft statt doppelt gebucht.</div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <button type="button" class="btn btn-sm btn-primary" x-show="!(link === 'existing' && transactionId === String(duplicate.id))" @click="useDuplicate()">Damit verknüpfen</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary" x-show="link !== 'new' && canBook()" @click="link = 'new'; dupDismissed = true">Trotzdem neu buchen</button>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="small text-body-secondary mt-2">Verknüpfte Buchungen werden in den Auswertungen nach den Kategorien der Posten aufgeteilt.</div>
                    </div>
                </div>

                <div class="mb-3">
                    <input class="form-control" x-model="note" placeholder="Notiz (optional)">
                </div>
                <div class="alert alert-danger" x-show="error" x-text="error" x-cloak></div>
                <div class="d-none d-lg-grid">
                    <button class="btn btn-primary btn-lg" :disabled="busy"><i class="bi bi-check-lg"></i> Einkauf speichern</button>
                </div>
            </div>
        </div>
        <!-- Mobil: Speichern unter den Posten, immer erreichbar -->
        <div class="sticky-actions d-grid d-lg-none mt-3">
            <button class="btn btn-primary btn-lg" :disabled="busy"><i class="bi bi-check-lg"></i> Einkauf speichern · <span x-text="HB.money(total())"></span></button>
        </div>
    </form>

    <?php if ($isEdit): ?>
        <form method="post" action="<?= e(url("/purchases/{$purchase['id']}/delete")) ?>" class="mt-4 text-end" onsubmit="return confirm('Einkauf löschen?')">
            <?= csrf_field() ?>
            <?php if ($purchase['transaction_id']): ?>
                <div class="form-check d-inline-block me-3">
                    <input class="form-check-input" type="checkbox" name="delete_transaction" value="1" id="deltx">
                    <label class="form-check-label small" for="deltx">verknüpfte Buchung auch löschen</label>
                </div>
            <?php endif; ?>
            <button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Einkauf löschen</button>
        </form>
    <?php endif; ?>
</div>
