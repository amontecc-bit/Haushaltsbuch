<?php
use App\Core\View;
use App\Services\InventoryService;

$cfg = ['items' => $items, 'catalog' => $catalog, 'openId' => $openId];
?>
<div x-data="shoppingItems(<?= e(json_encode($cfg)) ?>)">
    <p class="text-body-secondary small">
        Ein virtueller Posten (z. B. „Milch“) steht für mehrere echte Produkte aus deinen Einkäufen, etwa dieselbe Milch aus
        verschiedenen Läden. Die Einkaufsliste zeigt die Preisspanne über alle zugeordneten Produkte, und ein Einkauf
        eines davon hakt den Posten ab. Einen Überblick mit allen noch nicht zugeordneten Produkten bietet die
        <a href="<?= e(url('/shopping/assign')) ?>">Zuordnung</a>, dort lassen sich Produkte per Drag &amp; Drop verschieben.
    </p>

    <div class="alert alert-danger alert-dismissible" x-show="error" x-cloak>
        <span x-text="error"></span>
        <button type="button" class="btn-close" @click="error = ''" aria-label="Schließen"></button>
    </div>

    <div class="card mb-3">
        <div class="card-body d-flex flex-wrap gap-2">
            <input class="form-control flex-grow-1" style="min-width: 12rem" x-model="filter" placeholder="Posten oder Produkt suchen" aria-label="Suchen">
            <form class="d-flex gap-2 flex-grow-1" @submit.prevent="create()">
                <input class="form-control" x-model="newName" maxlength="190" placeholder="Neuer virtueller Posten" aria-label="Name">
                <button class="btn btn-primary text-nowrap" :disabled="!newName.trim()"><i class="bi bi-plus-lg"></i> Anlegen</button>
            </form>
        </div>
    </div>

    <template x-if="items.length === 0">
        <div class="card empty-state">
            <i class="bi bi-collection"></i>
            <p>Noch keine virtuellen Posten. Sie entstehen automatisch, wenn du Posten auf eine Einkaufsliste setzt, oder du legst sie hier an.</p>
        </div>
    </template>

    <div class="card" x-show="items.length">
        <div class="list-group list-group-flush">
            <template x-for="it in filtered()" :key="it.id">
                <div class="list-group-item" :id="'item-' + it.id">
                    <div class="d-flex align-items-center gap-2">
                        <span class="cat-dot sm" :style="'background:' + it.color"><i class="bi" :class="'bi-' + it.icon"></i></span>
                        <button type="button" class="btn btn-link p-0 text-start text-decoration-none text-body flex-grow-1 min-w-0" @click="toggle(it)">
                            <span class="fw-semibold" x-text="it.name"></span>
                            <small class="d-block text-body-secondary text-truncate"
                                   x-text="it.products.length ? it.products.map(p => p.name).join(' · ') : 'noch keine Produkte zugeordnet'"></small>
                        </button>
                        <small class="text-nowrap text-body-secondary" x-text="priceRange(it)"></small>
                        <i class="bi" :class="openId === it.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </div>

                    <div class="mt-3" x-show="openId === it.id" x-cloak>
                        <div class="row g-2 mb-3">
                            <div class="col-sm-6">
                                <label class="form-label small mb-1">Name</label>
                                <div class="input-group">
                                    <input class="form-control" x-model="editName" maxlength="190">
                                    <button type="button" class="btn btn-outline-primary" @click="save(it, { name: editName })">Speichern</button>
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label small mb-1">Kategorie (Gruppierung der Liste)</label>
                                <?= View::partial('partials/category_select', [
                                    'categories' => $categories, 'name' => 'category_id', 'type' => 'expense',
                                    'attrs' => ':value="it.category_id" @change="save(it, { category_id: $event.target.value })"',
                                ]) ?>
                            </div>
                        </div>

                        <fieldset class="border rounded p-2 mb-3">
                            <legend class="float-none w-auto px-1 small fw-semibold mb-0">Vorrat</legend>
                            <div class="row g-2 align-items-end">
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1">Referenzmenge</label>
                                    <div class="input-group">
                                        <input type="text" inputmode="decimal" class="form-control" :value="num(it.ref_quantity)"
                                               @change="save(it, { ref_quantity: $event.target.value })" aria-label="Referenzmenge">
                                        <select class="form-select" style="max-width: 6rem" @change="save(it, { ref_unit: $event.target.value })" aria-label="Einheit">
                                            <?php foreach (InventoryService::UNITS as $k => $label): ?>
                                                <option value="<?= e($k) ?>" :selected="it.ref_unit === '<?= e($k) ?>'"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1">Verbrauchsdauer (Tage)</label>
                                    <input type="number" min="1" max="3650" class="form-control" :value="it.shelf_days" placeholder="läuft nicht ab"
                                           @change="save(it, { shelf_days: $event.target.value })" aria-label="Verbrauchsdauer in Tagen">
                                </div>
                                <div class="col-6 col-md-3">
                                    <label class="form-label small mb-1">Mindestbestand (× Referenz)</label>
                                    <input type="text" inputmode="decimal" class="form-control" :value="num(it.min_stock)"
                                           @change="save(it, { min_stock: $event.target.value })" aria-label="Mindestbestand">
                                </div>
                                <div class="col-6 col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" :id="'auto-' + it.id" :checked="it.auto_restock"
                                               @change="save(it, { auto_restock: $event.target.checked })">
                                        <label class="form-check-label small" :for="'auto-' + it.id">automatisch nachkaufen</label>
                                    </div>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" :id="'staple-' + it.id" :checked="it.staple"
                                               @change="save(it, { staple: $event.target.checked })">
                                        <label class="form-check-label small" :for="'staple-' + it.id">Grundvorrat (immer da)</label>
                                    </div>
                                </div>
                            </div>
                            <small class="text-body-secondary d-block mt-1">
                                Der Vorrat wird in Vielfachen der Referenzmenge gezählt (z. B. 1 l Milch). Je Produkt unten: Faktor zur Referenz
                                (0,5-l-Packung = 0,5) und eigene Verbrauchsdauer (leer = wie oben).
                            </small>
                        </fieldset>

                        <label class="form-label small mb-1">Zugeordnete Produkte</label>
                        <div class="mb-2">
                            <template x-for="p in it.products" :key="p.id">
                                <div class="d-flex flex-wrap align-items-center gap-2 py-1 border-bottom">
                                    <a class="flex-grow-1 min-w-0 text-truncate small" :href="HB.url('/reports/product?id=' + p.id)" x-text="p.name"></a>
                                    <div class="input-group input-group-sm" style="width: 8.5rem" title="Faktor zur Referenzmenge">
                                        <span class="input-group-text">×</span>
                                        <input type="text" inputmode="decimal" class="form-control" :value="num(p.factor)"
                                               @change="saveLink(it, p, { factor: $event.target.value })" :aria-label="'Faktor für ' + p.name">
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-info" x-show="p.guess && Math.abs(p.guess - p.factor) > 0.0001"
                                            @click="saveLink(it, p, { factor: num(p.guess) })" title="Aus dem Produktnamen erkannt" x-text="'Vorschlag ×' + num(p.guess)"></button>
                                    <div class="input-group input-group-sm" style="width: 8.5rem" title="Verbrauchsdauer in Tagen">
                                        <input type="number" min="1" class="form-control" :value="p.shelf_days ?? ''" :placeholder="it.shelf_days || '–'"
                                               @change="saveLink(it, p, { shelf_days: $event.target.value })" :aria-label="'Verbrauchsdauer für ' + p.name">
                                        <span class="input-group-text">Tage</span>
                                    </div>
                                    <button type="button" class="btn-close" style="font-size: .6rem" @click="unlink(it, p)" :aria-label="p.name + ' entfernen'"></button>
                                </div>
                            </template>
                            <small class="text-body-secondary" x-show="!it.products.length">Noch keine – füge unten Produkte aus deinen Einkäufen hinzu.</small>
                        </div>
                        <div class="position-relative mb-3">
                            <input class="form-control" x-model="pq" autocomplete="off" placeholder="Produkt aus den Einkäufen hinzufügen" aria-label="Produkt suchen"
                                   @keydown.enter.prevent="productHits(it)[0] && link(it, productHits(it)[0])">
                            <div class="list-group shop-suggest shadow" x-show="pq.trim() !== ''" x-cloak>
                                <template x-for="p in productHits(it)" :key="p.id">
                                    <button type="button" class="list-group-item list-group-item-action d-flex gap-2" @click="link(it, p)">
                                        <span class="flex-grow-1 text-truncate" x-text="p.name"></span>
                                        <small class="text-nowrap" x-text="p.cnt + '× · ' + range(p.min, p.max, p.unit)"></small>
                                    </button>
                                </template>
                                <div class="list-group-item small text-body-secondary" x-show="!productHits(it).length">Kein passendes Produkt.</div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-danger" @click="destroy(it)"><i class="bi bi-trash"></i> Virtuellen Posten löschen</button>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
