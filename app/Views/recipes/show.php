<?php $id = (int) $recipe['id']; ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-body">
                <?php if ($classes): ?>
                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <?php foreach ($classes as $c): ?><span class="badge rounded-pill text-bg-light border"><?= e($c) ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <form method="get" action="<?= e(url("/recipes/$id")) ?>" class="d-flex align-items-center gap-2 mb-3">
                    <label class="small text-body-secondary" for="servings">Portionen</label>
                    <input type="number" min="1" max="99" class="form-control form-control-sm" style="width: 5rem" id="servings" name="servings" value="<?= (int) $servings ?>">
                    <button class="btn btn-sm btn-outline-primary">Umrechnen</button>
                    <?php if ($servings !== (int) $recipe['servings']): ?>
                        <small class="text-body-secondary">(Rezept: <?= (int) $recipe['servings'] ?>)</small>
                    <?php endif; ?>
                </form>

                <div class="d-flex align-items-center gap-2 mb-2">
                    <strong class="flex-grow-1">Zutaten</strong>
                    <span class="badge rounded-pill <?= $card['complete'] ? 'text-bg-success' : 'text-bg-warning' ?>">
                        <?= $card['complete'] ? 'alles da' : 'fehlt ' . (int) $card['missing_count'] . ' von ' . (int) $card['total'] ?>
                    </span>
                </div>
                <?php if (!$card['ingredients']): ?>
                    <p class="text-body-secondary small">Noch keine Zutaten – <a href="<?= e(url("/recipes/$id/edit")) ?>">Rezept bearbeiten</a>.</p>
                <?php else: ?>
                    <table class="table table-sm align-middle">
                        <thead><tr class="small text-body-secondary"><th>Zutat</th><th class="text-end">Menge</th><th class="text-end">vorhanden</th><th class="text-end">fehlt</th></tr></thead>
                        <tbody>
                        <?php foreach ($card['ingredients'] as $i): ?>
                            <tr>
                                <td><?= e($i['name']) ?><?php if ($i['note']): ?> <small class="text-body-secondary"><?= e($i['note']) ?></small><?php endif; ?></td>
                                <td class="text-end text-nowrap"><?= e($i['need']) ?></td>
                                <td class="text-end text-nowrap text-body-secondary"><?= $i['staple'] ? 'Grundvorrat' : e($i['have']) ?></td>
                                <td class="text-end text-nowrap <?= $i['missing'] ? 'text-danger fw-semibold' : 'text-success' ?>">
                                    <?= $i['missing'] ? e($i['missing']) : '<i class="bi bi-check-lg"></i>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <div class="d-grid gap-2">
                    <form method="post" action="<?= e(url("/recipes/$id/cook")) ?>"
                          onsubmit="return HB.confirmSubmit(this, 'Zutaten für <?= (int) $servings ?> Portionen vom Vorrat abbuchen?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="servings" value="<?= (int) $servings ?>">
                        <button class="btn btn-primary w-100"><i class="bi bi-fire"></i> Gekocht – vom Vorrat abbuchen</button>
                    </form>
                    <?php if (!$card['complete']): ?>
                        <form method="post" action="<?= e(url("/recipes/$id/shopping")) ?>" class="input-group">
                            <?= csrf_field() ?>
                            <input type="hidden" name="servings" value="<?= (int) $servings ?>">
                            <select class="form-select" name="list_id" aria-label="Einkaufsliste">
                                <?php foreach ($lists as $l): ?>
                                    <option value="<?= e((string) $l['id']) ?>"><?= e($l['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-outline-primary"><i class="bi bi-cart-plus"></i> Fehlendes auf Liste</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <strong class="d-block mb-2">Zubereitung</strong>
                <?php if (trim((string) $recipe['instructions']) === ''): ?>
                    <p class="text-body-secondary small mb-0">Noch keine Beschreibung.</p>
                <?php else: ?>
                    <div class="recipe-text"><?= nl2br(e($recipe['instructions'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<details class="card mt-2">
    <summary class="card-body py-2 small text-body-secondary">Rezept löschen</summary>
    <div class="card-body pt-0">
        <form method="post" action="<?= e(url("/recipes/$id/delete")) ?>" onsubmit="return HB.confirmSubmit(this, 'Rezept wirklich löschen?')">
            <?= csrf_field() ?>
            <button class="btn btn-outline-danger"><i class="bi bi-trash"></i> Löschen</button>
        </form>
    </div>
</details>
