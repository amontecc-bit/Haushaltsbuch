<div x-data="recipeSuggestions(<?= e(json_encode($cfg)) ?>)">
    <form method="get" action="<?= e(url('/recipes/suggestions')) ?>" class="card mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <label class="small text-body-secondary" for="servings">Portionen</label>
                <input type="number" min="1" max="99" class="form-control form-control-sm" style="width: 7.5rem" id="servings" name="servings"
                       value="<?= $servings ? (int) $servings : '' ?>" placeholder="wie Rezept">
                <label class="small text-body-secondary ms-sm-3" for="listSel">Fehlendes auf</label>
                <select class="form-select form-select-sm" style="width: auto" id="listSel" x-model="listId">
                    <template x-for="l in lists" :key="l.id"><option :value="l.id" x-text="l.name"></option></template>
                </select>
            </div>
            <?php foreach ($groups as $g): ?>
                <?php if (!$g['values']) continue; ?>
                <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
                    <small class="text-body-secondary me-1"><?= e($g['name']) ?>:</small>
                    <?php foreach ($g['values'] as $v): ?>
                        <input type="checkbox" class="btn-check" name="v[]" value="<?= (int) $v['id'] ?>" id="v<?= (int) $v['id'] ?>"
                               <?= in_array($v['id'], $values, true) ? 'checked' : '' ?> onchange="this.form.submit()">
                        <label class="btn btn-sm btn-outline-primary rounded-pill" for="v<?= (int) $v['id'] ?>"><?= e($v['name']) ?></label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <button class="btn btn-sm btn-primary mt-1">Anwenden</button>
        </div>
    </form>

    <template x-if="!recipes.length">
        <div class="card empty-state">
            <i class="bi bi-lightbulb"></i>
            <p>Keine Rezepte<?= $values ? ' mit dieser Auswahl' : '' ?>. <a href="<?= e(url('/recipes/create')) ?>">Rezept anlegen</a></p>
        </div>
    </template>

    <p class="small text-body-secondary" x-show="recipes.length" x-text="summary()"></p>

    <template x-for="r in shown()" :key="r.id">
        <div class="card mb-2">
            <div class="card-body py-2">
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-link p-0 text-start text-decoration-none text-body flex-grow-1 min-w-0" @click="openId = openId === r.id ? null : r.id">
                        <span class="fw-semibold" x-text="r.name"></span>
                        <small class="d-block text-body-secondary" x-text="portions(r.servings) + ' · ' + r.total + ' Zutaten'"></small>
                    </button>
                    <span class="badge rounded-pill" :class="r.complete ? 'text-bg-success' : (r.coverage >= 50 ? 'text-bg-warning' : 'text-bg-light border')"
                          x-text="r.complete ? 'alles da' : 'fehlt ' + r.missing_count + ' von ' + r.total"></span>
                    <i class="bi" :class="openId === r.id ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                </div>
                <div class="progress mt-2" style="height: 4px" role="progressbar" :aria-valuenow="r.coverage" aria-valuemin="0" aria-valuemax="100" :aria-label="'Vorrat deckt ' + r.coverage + ' %'">
                    <div class="progress-bar" :class="r.complete ? 'bg-success' : 'bg-warning'" :style="'width:' + r.coverage + '%'"></div>
                </div>

                <div class="mt-2" x-show="openId === r.id" x-cloak>
                    <table class="table table-sm align-middle mb-2">
                        <thead><tr class="small text-body-secondary"><th>Zutat</th><th class="text-end">benötigt</th><th class="text-end">vorhanden</th><th class="text-end">fehlt</th></tr></thead>
                        <tbody>
                        <template x-for="i in r.ingredients" :key="i.item_id + i.note">
                            <tr>
                                <td><span x-text="i.name"></span> <small class="text-body-secondary" x-show="i.note" x-text="i.note"></small></td>
                                <td class="text-end text-nowrap" x-text="i.need"></td>
                                <td class="text-end text-nowrap text-body-secondary" x-text="i.staple ? 'Grundvorrat' : i.have"></td>
                                <td class="text-end text-nowrap" :class="i.missing ? 'text-danger fw-semibold' : 'text-success'">
                                    <span x-show="i.missing" x-text="i.missing"></span><i class="bi bi-check-lg" x-show="!i.missing"></i>
                                </td>
                            </tr>
                        </template>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap gap-2 mt-2">
                    <a class="btn btn-sm btn-outline-primary" :href="HB.url('/recipes/' + r.id + '?servings=' + r.servings)"><i class="bi bi-journal-text"></i> Rezept</a>
                    <form method="post" :action="HB.url('/recipes/' + r.id + '/shopping')" x-show="!r.complete">
                        <?= csrf_field() ?>
                        <input type="hidden" name="servings" :value="r.servings">
                        <input type="hidden" name="list_id" :value="listId">
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-cart-plus"></i> Fehlendes auf Einkaufsliste</button>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <div class="text-center my-3" x-show="more()">
        <button type="button" class="btn btn-outline-primary" @click="limit += page">
            <i class="bi bi-plus-lg"></i> Weitere Vorschläge <span x-text="'(' + more() + ')'"></span>
        </button>
    </div>
</div>
