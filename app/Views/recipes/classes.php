<div x-data="recipeClasses(<?= e(json_encode(['groups' => $groups])) ?>)">
    <p class="text-body-secondary small">
        Klassifikationen ordnen Rezepte ein, z. B. <em>Mahlzeit</em>: morgens, mittags, abends oder <em>Art</em>: vegetarisch, Fleisch, Fisch.
        Ein Rezept kann je Gruppe mehrere Werte haben. In den Vorschlägen lässt sich danach filtern.
    </p>
    <div class="alert alert-danger" x-show="error" x-cloak x-text="error"></div>

    <template x-for="(g, gi) in groups" :key="g.key">
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex gap-2 mb-2">
                    <input class="form-control fw-semibold" x-model="g.name" maxlength="80" placeholder="Gruppe (z. B. Mahlzeit)" aria-label="Name der Gruppe">
                    <button type="button" class="btn btn-outline-danger" @click="removeGroup(gi)" aria-label="Gruppe löschen"><i class="bi bi-trash"></i></button>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <template x-for="(v, vi) in g.values" :key="v.key">
                        <div class="input-group input-group-sm" style="width: 12rem">
                            <input class="form-control" x-model="v.name" maxlength="80" aria-label="Wert">
                            <button type="button" class="btn btn-outline-secondary" @click="g.values.splice(vi, 1)" aria-label="Wert entfernen"><i class="bi bi-x-lg"></i></button>
                        </div>
                    </template>
                    <button type="button" class="btn btn-sm btn-outline-primary" @click="addValue(g)"><i class="bi bi-plus-lg"></i> Wert</button>
                </div>
            </div>
        </div>
    </template>

    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-primary" @click="addGroup()"><i class="bi bi-plus-lg"></i> Gruppe</button>
        <button type="button" class="btn btn-primary ms-auto" @click="save()" :disabled="busy"><i class="bi bi-check-lg"></i> Speichern</button>
    </div>
</div>
