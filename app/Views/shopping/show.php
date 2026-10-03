<?php
use App\Services\ShoppingListService;

$cfg = ['listId' => (int) $list['id'], 'entries' => $entries, 'items' => $items, 'catalog' => $catalog];
?>
<div x-data="shoppingList(<?= e(json_encode($cfg)) ?>)">
    <div class="alert alert-dismissible" :class="'alert-' + msg.type" x-show="msg.text" x-cloak>
        <span x-text="msg.text"></span>
        <button type="button" class="btn-close" @click="msg.text = ''" aria-label="Schließen"></button>
    </div>

    <!-- Posten hinzufügen -->
    <div class="card mb-3">
        <div class="card-body shop-add">
            <div class="shop-search position-relative">
                <input type="text" class="form-control" x-model="q" x-ref="search" autocomplete="off"
                       placeholder="Posten suchen oder neu eingeben" aria-label="Posten"
                       @input="hl = 0" @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                       @keydown.enter.prevent="addHighlighted()" @keydown.escape="q = ''" @blur="setTimeout(() => focused = false, 150)" @focus="focused = true">
                <div class="list-group shop-suggest shadow" x-show="focused && q.trim() !== ''" x-cloak>
                    <template x-for="(s, i) in suggestions()" :key="s.key">
                        <button type="button" class="list-group-item list-group-item-action d-flex gap-2 align-items-center"
                                :class="{ active: i === hl }" @mousedown.prevent="add(s)">
                            <i class="bi" :class="s.type === 'item' ? 'bi-collection' : (s.type === 'product' ? 'bi-bag' : 'bi-plus-circle')"></i>
                            <span class="flex-grow-1 text-truncate">
                                <span x-text="s.label"></span>
                                <small class="d-block text-truncate opacity-75" x-show="s.sub" x-text="s.sub"></small>
                            </span>
                            <small class="text-nowrap" x-show="s.price" x-text="s.price"></small>
                        </button>
                    </template>
                </div>
            </div>
            <input type="text" inputmode="decimal" class="form-control shop-qty" x-model="qty" aria-label="Menge">
            <select class="form-select shop-unit" x-model="unit" aria-label="Einheit">
                <?php foreach (ShoppingListService::UNITS as $k => $label): ?>
                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="btn btn-primary" @click="addHighlighted()" :disabled="busy || q.trim() === ''"><i class="bi bi-plus-lg"></i> Hinzufügen</button>
        </div>
    </div>

    <template x-if="open().length === 0">
        <div class="card empty-state">
            <i class="bi bi-card-checklist"></i>
            <p x-text="entries.length ? 'Alles erledigt.' : 'Die Liste ist noch leer. Suche oben nach Posten aus deinen Einkäufen oder gib einen neuen ein.'"></p>
        </div>
    </template>

    <!-- Offene Posten nach Kategorie -->
    <template x-for="g in groups()" :key="g.name">
        <div class="card mb-3">
            <div class="card-header bg-transparent small fw-semibold text-body-secondary" x-text="g.name"></div>
            <div class="list-group list-group-flush">
                <template x-for="e in g.entries" :key="e.id">
                    <div class="list-group-item">
                        <div class="shop-entry">
                            <input type="checkbox" class="form-check-input shop-check" :checked="e.done" @change="toggle(e)" :aria-label="e.name + ' abhaken'">
                            <button type="button" class="btn btn-sm btn-light shop-qty-btn" @click="edit(e)" x-text="e.qty_label" title="Menge ändern"></button>
                            <div class="shop-name">
                                <div class="fw-semibold" x-text="e.name"></div>
                                <div class="small text-body-secondary text-truncate" x-show="e.products.length" x-text="e.products.join(' · ')"></div>
                                <div class="small fst-italic" x-show="e.note" x-text="e.note"></div>
                                <div class="small d-sm-none" x-show="e.min !== null" x-text="priceRange(e) + (e.min_store ? ' · günstig: ' + e.min_store : '')"></div>
                            </div>
                            <div class="shop-price d-none d-sm-block">
                                <div x-text="priceRange(e)"></div>
                                <div class="text-body-secondary text-truncate" x-show="e.min_store" x-text="'günstig: ' + e.min_store"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-link text-body-secondary px-1" @click="edit(e)" aria-label="Bearbeiten"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="btn btn-sm btn-link text-body-secondary px-1" @click="remove(e)" aria-label="Entfernen"><i class="bi bi-x-lg"></i></button>
                        </div>
                        <div class="shop-edit" x-show="editId === e.id" x-cloak>
                            <input type="text" inputmode="decimal" class="form-control form-control-sm shop-qty" x-model="editQty" aria-label="Menge">
                            <select class="form-select form-select-sm shop-unit" x-model="editUnit" aria-label="Einheit">
                                <?php foreach (ShoppingListService::UNITS as $k => $label): ?>
                                    <option value="<?= e($k) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" class="form-control form-control-sm" x-model="editNote" maxlength="190" placeholder="Notiz (z. B. Marke, Bio)" aria-label="Notiz">
                            <button type="button" class="btn btn-sm btn-primary" @click="saveEdit(e)">Speichern</button>
                            <a class="btn btn-sm btn-outline-secondary" :href="HB.url('/shopping/items?open=' + e.item_id)">
                                <i class="bi bi-collection"></i> Virtuellen Posten bearbeiten (Name, Kategorie, Produkte)
                            </a>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </template>

    <!-- Erledigt -->
    <div class="card mb-3" x-show="done().length" x-cloak>
        <div class="card-header bg-transparent d-flex align-items-center gap-2">
            <button type="button" class="btn btn-link p-0 text-decoration-none" @click="showDone = !showDone">
                <i class="bi" :class="showDone ? 'bi-chevron-down' : 'bi-chevron-right'"></i>
                <strong x-text="'Erledigt (' + done().length + ')'"></strong>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="clearDone()">Erledigte entfernen</button>
        </div>
        <div class="list-group list-group-flush" x-show="showDone">
            <template x-for="e in done()" :key="e.id">
                <div class="list-group-item shop-entry shop-done">
                    <input type="checkbox" class="form-check-input shop-check" checked @change="toggle(e)" :aria-label="e.name + ' wieder öffnen'">
                    <span class="shop-qty-btn small" x-text="e.qty_label"></span>
                    <div class="shop-name">
                        <div class="text-decoration-line-through" x-text="e.name"></div>
                        <template x-if="e.purchase">
                            <a class="small" :href="HB.url('/purchases/' + e.purchase.id)" x-text="'gekauft: ' + e.purchase.label"></a>
                        </template>
                    </div>
                </div>
            </template>
        </div>
    </div>

    <!-- Abgleich mit einem Einkauf -->
    <div class="modal fade" id="matchModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mit Einkauf abgleichen</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-body-secondary">Posten der Liste, die im Einkauf vorkommen, werden abgehakt. Neu gespeicherte Einkäufe
                        werden automatisch abgeglichen.</p>
                    <?php if (!$purchases): ?>
                        <p class="mb-0">Keine Einkäufe in den letzten 30 Tagen.</p>
                    <?php else: ?>
                        <select class="form-select" x-model="purchaseId" aria-label="Einkauf">
                            <?php foreach ($purchases as $p): ?>
                                <option value="<?= (int) $p['id'] ?>"><?= e(date_de($p['purchase_date']) . ' · ' . ($p['store'] ?: 'Einkauf') . ' · ' . $p['item_count'] . ' Posten · ' . money($p['total'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                    <?php if ($purchases): ?>
                        <button type="button" class="btn btn-primary" @click="match()" :disabled="busy">Abgleichen</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<details class="card mt-4">
    <summary class="card-body py-2 small text-body-secondary">Liste umbenennen oder löschen</summary>
    <div class="card-body pt-0 d-flex flex-wrap gap-2">
        <form method="post" action="<?= e(url("/shopping/{$list['id']}")) ?>" class="d-flex gap-2 flex-grow-1">
            <?= csrf_field() ?>
            <input class="form-control" name="name" value="<?= e($list['name']) ?>" maxlength="120" required aria-label="Name">
            <button class="btn btn-outline-primary">Umbenennen</button>
        </form>
        <form method="post" action="<?= e(url("/shopping/{$list['id']}/delete")) ?>" onsubmit="return HB.confirmSubmit(this, 'Liste wirklich löschen?')">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Löschen</button>
        </form>
    </div>
</details>
