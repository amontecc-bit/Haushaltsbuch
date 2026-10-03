<?php $cfg = ['items' => $items]; ?>
<div x-data="inventoryList(<?= e(json_encode($cfg)) ?>)">
    <p class="text-body-secondary small">
        Der Vorrat wird je <a href="<?= e(url('/shopping/items')) ?>">virtuellem Posten</a> geführt. Jeder Einkauf eines zugeordneten
        Produkts erhöht ihn (mit dem Ausgleichsfaktor des Produkts), nach der Verbrauchsdauer gilt er als verbraucht. Kochen eines
        Rezepts bucht die Zutaten ab. Aufgebrauchte oder abgelaufene Posten landen auf der automatischen Einkaufsliste.
    </p>

    <div class="alert alert-danger alert-dismissible" x-show="error" x-cloak>
        <span x-text="error"></span>
        <button type="button" class="btn-close" @click="error = ''" aria-label="Schließen"></button>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-2 align-items-center">
            <input class="form-control flex-grow-1" style="min-width: 12rem; max-width: 30rem" x-model="filter" placeholder="Posten suchen" aria-label="Suchen">
            <div class="form-check form-switch mb-0">
                <input class="form-check-input" type="checkbox" id="invAll" x-model="showAll">
                <label class="form-check-label" for="invAll">auch Posten ohne Vorrat</label>
            </div>
            <form method="post" action="<?= e(url('/inventory/rebuild')) ?>" class="ms-auto"
                  onsubmit="return HB.confirmSubmit(this, 'Vorrat aus den Einkäufen der letzten 90 Tage neu abgleichen? Korrekturen von Hand bleiben erhalten.')">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat"></i> Aus Einkäufen abgleichen</button>
            </form>
        </div>
    </div>

    <template x-if="!filtered().length">
        <div class="card empty-state">
            <i class="bi bi-box-seam"></i>
            <p>Noch nichts im Vorrat. Ordne bei den virtuellen Posten deine Produkte zu und speichere Einkäufe – oder gleiche den
                Vorrat oben aus den bisherigen Einkäufen ab. Mit dem Schalter siehst du alle Posten und kannst Bestände von Hand eintragen.</p>
        </div>
    </template>

    <div class="card" x-show="filtered().length">
        <div class="list-group list-group-flush">
            <template x-for="it in filtered()" :key="it.id">
                <div class="list-group-item">
                    <div class="d-flex align-items-center gap-2">
                        <span class="cat-dot sm" :style="'background:' + it.color"><i class="bi" :class="'bi-' + it.icon"></i></span>
                        <button type="button" class="btn btn-link p-0 text-start text-decoration-none text-body flex-grow-1 min-w-0" @click="toggle(it)">
                            <span class="fw-semibold" x-text="it.name"></span>
                            <small class="d-block text-body-secondary text-truncate" x-text="sub(it)"></small>
                        </button>
                        <span class="text-nowrap fw-semibold" x-show="it.status !== 'staple' && it.status !== 'none'" x-text="it.stock_label"></span>
                        <span class="badge rounded-pill" :class="badge(it.status)[0]" x-text="badge(it.status)[1]"></span>
                        <i class="bi" :class="openId === it.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </div>

                    <div class="mt-3" x-show="openId === it.id" x-cloak>
                        <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
                            <button type="button" class="btn btn-outline-secondary" @click="adjust(it, { mode: 'delta', steps: -1 })" :disabled="busy || it.stock <= 0"
                                    :title="'− ' + it.ref_label"><i class="bi bi-dash-lg"></i> <span x-text="it.ref_label"></span></button>
                            <button type="button" class="btn btn-outline-secondary" @click="adjust(it, { mode: 'delta', steps: 1 })" :disabled="busy"
                                    :title="'+ ' + it.ref_label"><i class="bi bi-plus-lg"></i> <span x-text="it.ref_label"></span></button>
                            <div class="input-group" style="max-width: 15rem">
                                <input type="text" inputmode="decimal" class="form-control" x-model="setValue" aria-label="Bestand" @keydown.enter.prevent="adjust(it, { mode: 'set', value: setValue })">
                                <span class="input-group-text" x-text="it.unit"></span>
                                <button type="button" class="btn btn-outline-primary" @click="adjust(it, { mode: 'set', value: setValue })" :disabled="busy">Setzen</button>
                            </div>
                            <button type="button" class="btn btn-outline-danger" @click="adjust(it, { mode: 'empty' })" :disabled="busy || it.stock <= 0">
                                <i class="bi bi-trash3"></i> Alles verbraucht
                            </button>
                        </div>

                        <div x-show="it.lots.length">
                            <div class="small fw-semibold text-body-secondary mb-1">Chargen (neueste zuerst)</div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-2">
                                    <thead><tr class="small text-body-secondary"><th>Herkunft</th><th>Datum</th><th class="text-end">Menge</th><th>Rest</th><th>läuft ab</th><th></th></tr></thead>
                                    <tbody>
                                    <template x-for="l in it.lots" :key="l.id">
                                        <tr :class="{ 'text-body-secondary': l.expired || l.remaining <= 0 }">
                                            <td class="small">
                                                <template x-if="l.purchase_id"><a :href="HB.url('/purchases/' + l.purchase_id)" x-text="l.label"></a></template>
                                                <span x-show="!l.purchase_id" x-text="l.label"></span>
                                                <span class="badge text-bg-light border ms-1" x-show="l.expired">abgelaufen</span>
                                            </td>
                                            <td class="small text-nowrap" x-text="invDate(l.acquired_on)"></td>
                                            <td class="small text-end text-nowrap" x-text="l.quantity"></td>
                                            <td style="min-width: 6.5rem">
                                                <input type="text" inputmode="decimal" class="form-control form-control-sm" :value="String(l.remaining).replace('.', ',')"
                                                       @change="updateLot(l, { remaining: $event.target.value })" :aria-label="'Rest in ' + it.unit">
                                            </td>
                                            <td style="min-width: 9rem">
                                                <input type="date" class="form-control form-control-sm" :value="l.expires_on || ''"
                                                       @change="updateLot(l, { expires_on: $event.target.value })" aria-label="Ablaufdatum">
                                            </td>
                                            <td class="text-end"><button type="button" class="btn btn-sm btn-link text-body-secondary px-1" @click="deleteLot(l)" aria-label="Charge löschen"><i class="bi bi-x-lg"></i></button></td>
                                        </tr>
                                    </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <a class="small" :href="HB.url('/shopping/items?open=' + it.id)"><i class="bi bi-gear"></i> Referenzmenge, Verbrauchsdauer, Mindestbestand und Produkte einstellen</a>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
