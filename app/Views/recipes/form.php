<div x-data="recipeForm(<?= e(json_encode($cfg)) ?>)">
    <div class="alert alert-danger" x-show="error" x-cloak x-text="error"></div>

    <form @submit.prevent="save()">
        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row g-2 mb-3">
                            <div class="col-8">
                                <label class="form-label" for="rName">Name</label>
                                <input class="form-control" id="rName" x-model="name" maxlength="190" required>
                            </div>
                            <div class="col-4">
                                <label class="form-label" for="rServings">Portionen</label>
                                <input type="number" min="1" max="99" class="form-control" id="rServings" x-model="servings" required>
                            </div>
                        </div>
                        <?php foreach ($groups as $g): ?>
                            <?php if (!$g['values']) continue; ?>
                            <div class="d-flex flex-wrap align-items-center gap-1 mb-2">
                                <small class="text-body-secondary me-1"><?= e($g['name']) ?>:</small>
                                <?php foreach ($g['values'] as $v): ?>
                                    <button type="button" class="btn btn-sm rounded-pill" :class="values.includes(<?= (int) $v['id'] ?>) ? 'btn-primary' : 'btn-outline-secondary'"
                                            @click="toggleValue(<?= (int) $v['id'] ?>)"><?= e($v['name']) ?></button>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <a class="small" href="<?= e(url('/recipes/classes')) ?>"><i class="bi bi-tags"></i> Klassifikation bearbeiten</a>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-body">
                        <strong class="d-block mb-1">Zutaten</strong>
                        <p class="small text-body-secondary">Jede Zutat ist ein virtueller Posten (wie auf der Einkaufsliste). Neue Namen werden als
                            virtuelle Posten angelegt. Die Einheit muss zur Referenz des Postens passen (Gewicht, Volumen oder Stück).</p>
                        <template x-for="(row, idx) in ingredients" :key="row.key">
                            <div class="recipe-row mb-2">
                                <div class="position-relative recipe-row-name">
                                    <input class="form-control" x-model="row.name" placeholder="Zutat" autocomplete="off" :aria-label="'Zutat ' + (idx + 1)"
                                           @input="row.item_id = null; sugIdx = idx" @focus="sugIdx = idx" @blur="setTimeout(() => { if (sugIdx === idx) sugIdx = null }, 150)">
                                    <div class="list-group shop-suggest shadow" x-show="sugIdx === idx && row.name.trim() !== '' && !row.item_id && hits(row).length" x-cloak>
                                        <template x-for="it in hits(row)" :key="it.id">
                                            <button type="button" class="list-group-item list-group-item-action" @mousedown.prevent="pick(row, it)" x-text="it.name"></button>
                                        </template>
                                    </div>
                                </div>
                                <input type="text" inputmode="decimal" class="form-control recipe-row-qty" x-model="row.quantity" placeholder="Menge" aria-label="Menge">
                                <select class="form-select recipe-row-unit" x-model="row.unit" aria-label="Einheit">
                                    <template x-for="u in unitsFor(row)" :key="u">
                                        <option :value="u" x-text="units[u]" :selected="row.unit === u"></option>
                                    </template>
                                </select>
                                <input class="form-control recipe-row-note" x-model="row.note" maxlength="190" placeholder="Notiz (z. B. gehackt)" aria-label="Notiz">
                                <button type="button" class="btn btn-link text-body-secondary px-1" @click="ingredients.splice(idx, 1)" aria-label="Zutat entfernen"><i class="bi bi-x-lg"></i></button>
                            </div>
                        </template>
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="addRow()"><i class="bi bi-plus-lg"></i> Zutat</button>
                    </div>
                </div>
            </div>

            <div class="col-xl-5">
                <div class="card mb-3">
                    <div class="card-body">
                        <label class="form-label" for="rText"><strong>Zubereitung</strong></label>
                        <textarea class="form-control" id="rText" x-model="instructions" rows="16" placeholder="Schritte, Zeiten, Tipps …"></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-grid d-sm-flex gap-2">
            <button class="btn btn-primary btn-lg" :disabled="busy"><i class="bi bi-check-lg"></i> Speichern</button>
            <a class="btn btn-outline-secondary btn-lg" href="<?= e(url($back)) ?>">Abbrechen</a>
        </div>
    </form>
</div>
