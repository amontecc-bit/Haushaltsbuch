<?php $cfg = ['recipes' => $recipes, 'groups' => array_map(fn ($g) => array_column($g['values'], 'id'), $groups)]; ?>
<div x-data="recipeIndex(<?= e(json_encode($cfg)) ?>)">
    <div class="card mb-3">
        <div class="card-body">
            <input class="form-control mb-2" x-model="filter" placeholder="Rezept suchen" aria-label="Rezept suchen">
            <?php foreach ($groups as $g): ?>
                <?php if (!$g['values']) continue; ?>
                <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                    <small class="text-body-secondary me-1"><?= e($g['name']) ?>:</small>
                    <?php foreach ($g['values'] as $v): ?>
                        <button type="button" class="btn btn-sm rounded-pill" :class="has(<?= (int) $v['id'] ?>) ? 'btn-primary' : 'btn-outline-secondary'"
                                @click="toggleValue(<?= (int) $g['id'] ?>, <?= (int) $v['id'] ?>)"><?= e($v['name']) ?></button>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <template x-if="!recipes.length">
        <div class="card empty-state">
            <i class="bi bi-journal-richtext"></i>
            <p>Noch keine Rezepte. Lege ein Rezept mit seiner Zutatenliste aus virtuellen Posten an – dann schlägt dir die App vor,
                was du mit dem Vorrat kochen kannst.</p>
            <a class="btn btn-primary" href="<?= e(url('/recipes/create')) ?>"><i class="bi bi-plus-lg"></i> Neues Rezept</a>
        </div>
    </template>

    <div class="card" x-show="recipes.length">
        <div class="list-group list-group-flush tx-list">
            <template x-for="r in filtered()" :key="r.id">
                <a class="list-group-item list-group-item-action" :href="HB.url('/recipes/' + r.id)">
                    <span class="cat-dot" style="background: #1baf7a"><i class="bi bi-journal-richtext"></i></span>
                    <div class="tx-main">
                        <div class="tx-title" x-text="r.name"></div>
                        <div class="tx-sub" x-text="r.ingredients + ' Zutaten · ' + portions(r.servings)"></div>
                    </div>
                    <span class="badge rounded-pill" :class="r.complete ? 'text-bg-success' : 'text-bg-light border'"
                          x-text="r.complete ? 'machbar' : 'fehlt ' + r.missing"></span>
                </a>
            </template>
            <div class="list-group-item text-body-secondary small" x-show="!filtered().length">Kein Rezept passt zum Filter.</div>
        </div>
    </div>
</div>
