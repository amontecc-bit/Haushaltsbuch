<?php $cfg = ['items' => $items, 'catalog' => $catalog]; ?>
<div x-data="shoppingAssign(<?= e(json_encode($cfg)) ?>)" @keydown.escape.window="selected = null">
    <p class="text-body-secondary small mb-2">
        Ziehe Produkte aus deinen Einkäufen auf einen virtuellen Posten, zwischen Posten hin und her oder zurück nach
        „Nicht zugeordnet“. Mit gedrückter <kbd>Strg</kbd>-Taste wird kopiert statt verschoben, dann gehört das Produkt
        zu mehreren Posten. Am Handy: Produkt antippen, dann das Ziel antippen.
    </p>

    <div class="alert alert-danger alert-dismissible" x-show="error" x-cloak>
        <span x-text="error"></span>
        <button type="button" class="btn-close" @click="error = ''" aria-label="Schließen"></button>
    </div>

    <div class="card mb-3 assign-toolbar">
        <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
            <input class="form-control flex-grow-1" style="min-width: 12rem; max-width: 28rem" x-model="filter" placeholder="Posten oder Produkt suchen" aria-label="Suchen">
            <template x-if="selected">
                <div class="d-flex align-items-center gap-2 small">
                    <span class="badge text-bg-primary" x-text="selected.p.name"></span> ausgewählt – Ziel antippen
                    <button type="button" class="btn btn-sm btn-link p-0" @click="selected = null">Abbrechen</button>
                </div>
            </template>
            <span class="small text-body-secondary ms-auto" x-show="busy" x-cloak><span class="spinner-border spinner-border-sm"></span> speichert …</span>
        </div>
    </div>

    <div class="row g-3">
        <!-- Virtuelle Posten -->
        <div class="col-lg-8 order-2 order-lg-1">
            <h2 class="h6 text-body-secondary mb-2">Virtuelle Posten (<span x-text="items.length"></span>)</h2>
            <div class="assign-grid">
                <!-- Ablage für neuen Posten -->
                <div class="card assign-zone assign-new" :class="zoneClass('new')"
                     @dragover.prevent="over = 'new'" @dragleave="leave('new')" @drop.prevent="drop($event, 'new')" @click="tapZone('new')">
                    <div class="card-body text-center text-body-secondary small">
                        <i class="bi bi-plus-circle d-block fs-4 mb-1"></i>
                        Hierher ziehen = neuer virtueller Posten mit dem Namen des Produkts
                    </div>
                </div>
                <template x-for="it in filteredItems()" :key="it.id">
                    <div class="card assign-zone" :class="zoneClass(it.id)"
                         @dragover.prevent="over = it.id" @dragleave="leave(it.id)" @drop.prevent="drop($event, it.id)" @click="tapZone(it.id)">
                        <div class="card-header bg-transparent d-flex align-items-center gap-2 py-2">
                            <span class="cat-dot sm" :style="'background:' + it.color"><i class="bi" :class="'bi-' + it.icon"></i></span>
                            <a class="fw-semibold text-body text-decoration-none text-truncate" :href="HB.url('/shopping/items?open=' + it.id)" @click.stop x-text="it.name" title="Bearbeiten"></a>
                            <small class="ms-auto text-body-secondary text-nowrap" x-text="range(it)"></small>
                        </div>
                        <div class="card-body assign-chips">
                            <template x-for="p in it.products" :key="p.id">
                                <span class="assign-chip" draggable="true" :class="{ selected: isSelected(p, it.id) }"
                                      @dragstart="dragStart($event, p, it.id)" @dragend="over = null" @click.stop="tapChip(p, it.id)" x-text="p.name"></span>
                            </template>
                            <small class="text-body-secondary" x-show="!it.products.length">Produkte hierher ziehen</small>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Nicht zugeordnete Produkte -->
        <div class="col-lg-4 order-1 order-lg-2">
            <div class="card assign-zone assign-unassigned" :class="zoneClass('none')"
                 @dragover.prevent="over = 'none'" @dragleave="leave('none')" @drop.prevent="drop($event, 'none')" @click="tapZone('none')">
                <div class="card-header bg-transparent py-2">
                    <strong>Nicht zugeordnet</strong> <span class="text-body-secondary" x-text="'(' + unassigned().length + ')'"></span>
                    <div class="small text-body-secondary">Produkte aus deinen Einkäufen, meistgekaufte zuerst</div>
                </div>
                <div class="card-body assign-chips">
                    <template x-for="p in unassignedShown()" :key="p.id">
                        <span class="assign-chip" draggable="true" :class="{ selected: isSelected(p, null) }"
                              @dragstart="dragStart($event, p, null)" @dragend="over = null" @click.stop="tapChip(p, null)"
                              :title="p.cnt + '× gekauft'">
                            <span x-text="p.name"></span> <small class="opacity-75" x-text="p.cnt + '×'"></small>
                        </span>
                    </template>
                    <small class="text-body-secondary" x-show="!unassigned().length">Alle Produkte sind zugeordnet.</small>
                    <small class="text-body-secondary d-block mt-2" x-show="unassigned().length > unassignedShown().length"
                           x-text="'… und ' + (unassigned().length - unassignedShown().length) + ' weitere – Suche benutzen'"></small>
                </div>
            </div>
        </div>
    </div>
</div>
