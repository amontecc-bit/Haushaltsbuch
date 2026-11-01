<?php
$isEdit = !empty($w['id']);
$action = $isEdit ? url("/wishes/{$w['id']}") : url('/wishes');
$init = [
    'hasPlan'   => (bool) ($w['has_plan'] ?? false),
    'price'     => (string) $w['price'],
    'rate'      => (string) $w['plan_amount'],
    'saved'     => (string) $w['plan_saved'],
    'start'     => (string) ($w['plan_start'] ?: date('Y-m-d')),
    'wantedBy'  => (string) $w['wanted_by'],
    'postenId'  => $w['plan_recurring_id'] ? (string) $w['plan_recurring_id'] : '',
    'accountId' => $w['plan_account_id'] ? (string) $w['plan_account_id'] : '',
    'posten'    => $posten,
];
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($action) ?>" enctype="multipart/form-data" class="card" x-data="wishForm(<?= e(json_encode($init)) ?>)">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label" for="title">Überschrift</label>
                    <input class="form-control form-control-lg" id="title" name="title" value="<?= e($w['title']) ?>" placeholder="z. B. Neues Fahrrad, Lego-Eisenbahn" required maxlength="190" <?= $isEdit ? '' : 'autofocus' ?>>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Beschreibung</label>
                    <textarea class="form-control" id="description" name="description" rows="3" placeholder="Modell, Größe, Farbe, wo es das gibt …"><?= e($w['description']) ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label" for="image">Bild</label>
                    <?php if (!empty($w['image_path'])): ?>
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <img src="<?= e(url("/wishes/{$w['id']}/image")) ?>" alt="" class="rounded wish-thumb">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="remove_image">
                                <label class="form-check-label" for="remove_image">Bild entfernen</label>
                            </div>
                        </div>
                    <?php endif; ?>
                    <input type="file" class="form-control" id="image" name="image" accept="image/jpeg,image/png,image/webp" @change="showImage($event)">
                    <img x-show="imageUrl" :src="imageUrl" alt="" class="rounded wish-thumb mt-2" style="display: none">
                    <div class="form-text"><?= !empty($w['image_path']) ? 'Ein neues Bild ersetzt das bisherige. ' : '' ?>Foto oder Screenshot (JPG, PNG, WebP) – große Fotos werden verkleinert.</div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="price">Preis (€)</label>
                        <input class="form-control text-end" id="price" name="price" inputmode="decimal" x-model="price" placeholder="0,00">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="wanted_by">Wunschtermin</label>
                        <input type="date" class="form-control" id="wanted_by" name="wanted_by" x-model="wantedBy">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="occasion">Anlass</label>
                        <input class="form-control" id="occasion" name="occasion" value="<?= e($w['occasion']) ?>" list="occasion-list" placeholder="z. B. Weihnachten" maxlength="100">
                        <datalist id="occasion-list"><?php foreach ($occasions as $o): ?><option value="<?= e($o) ?>"><?php endforeach; ?></datalist>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="for_user_id">Für wen</label>
                        <select class="form-select" id="for_user_id" name="for_user_id">
                            <option value="">ganze Familie</option>
                            <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= selected($u['id'], $w['for_user_id']) ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="link">Link</label>
                        <input type="url" class="form-control" id="link" name="link" value="<?= e($w['link']) ?>" placeholder="https://…" maxlength="500">
                    </div>
                </div>

                <?php if ($planAllowed): ?>
                    <div class="border rounded p-3" id="plan">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="has_plan" name="has_plan" value="1" x-model="hasPlan">
                            <label class="form-check-label fw-semibold" for="has_plan"><i class="bi bi-piggy-bank"></i> Sparplan</label>
                        </div>
                        <div class="form-text mt-0" x-show="!hasPlan">Monatlich einen Betrag zurücklegen und sehen, ab wann der Wunsch bezahlbar ist.</div>

                        <div x-show="hasPlan" style="display: none" class="mt-3">
                            <div class="mb-3">
                                <label class="form-label" for="plan_recurring_id">Bezahlt aus Posten</label>
                                <select class="form-select" id="plan_recurring_id" name="plan_recurring_id" x-model="postenId" @change="postenChanged()">
                                    <option value="">– keinem Posten zugeordnet –</option>
                                    <?php foreach (['transfer' => 'Umbuchungen', 'income' => 'Einnahmen', 'expense' => 'Ausgaben'] as $kind => $label): ?>
                                        <?php $list = array_filter($posten, fn ($p) => $p['kind'] === $kind); if (!$list) { continue; } ?>
                                        <optgroup label="<?= e($label) ?>">
                                            <?php foreach ($list as $p): ?>
                                                <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> · <?= e($p['account']) ?> · <?= money($p['monthly'], true) ?>/Monat<?= $p['runs'] ? '' : ' (pausiert)' ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">
                                    Z. B. der Dauerauftrag aufs Sparkonto. Alle Sparpläne eines Postens werden zusammengezählt.
                                    <?php if (!$posten): ?>Noch keine Fixkosten angelegt – <a href="<?= e(url('/recurring/new')) ?>">Posten anlegen</a>.<?php endif; ?>
                                </div>
                                <template x-if="selected">
                                    <div class="small mt-1" :class="overBy > 0 ? 'text-danger fw-semibold' : 'text-success'">
                                        <span x-show="overBy <= 0">Bei diesem Posten sind noch <span x-text="fmt(selected.free)"></span>/Monat frei<span x-show="rateCents > 0"> – nach diesem Sparplan noch <span x-text="fmt(selected.free - rateCents)"></span></span>.</span>
                                        <span x-show="overBy > 0"><i class="bi bi-exclamation-triangle-fill"></i> Mit dieser Rate übersteigen die Sparpläne den Posten um <span x-text="fmt(overBy)"></span>/Monat.</span>
                                    </div>
                                </template>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="plan_account_id">Erspartes liegt auf Konto</label>
                                <select class="form-select" id="plan_account_id" name="plan_account_id" x-model="accountId" :disabled="lockedAccount">
                                    <option value="">– bitte wählen –</option>
                                    <?php foreach ($accounts as $acc): ?><option value="<?= (int) $acc['id'] ?>"><?= e($acc['name']) ?></option><?php endforeach; ?>
                                </select>
                                <input type="hidden" name="plan_account_id" :value="accountId" :disabled="!lockedAccount">
                                <div class="form-text" x-show="lockedAccount">Konto der Einnahmeseite des Postens.</div>
                            </div>

                            <div class="row g-2">
                                <div class="col-6 col-md-4">
                                    <label class="form-label" for="plan_amount">Rate / Monat (€)</label>
                                    <input class="form-control text-end" id="plan_amount" name="plan_amount" inputmode="decimal" x-model="rate" placeholder="0,00">
                                </div>
                                <div class="col-6 col-md-4">
                                    <label class="form-label" for="plan_start"><?= $isEdit ? 'Nächste Rate am' : 'Erste Rate am' ?></label>
                                    <input type="date" class="form-control" id="plan_start" name="plan_start" x-model="start">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="plan_saved">Bereits angespart (€)</label>
                                    <input class="form-control text-end" id="plan_saved" name="plan_saved" inputmode="decimal" x-model="saved" placeholder="0,00">
                                </div>
                            </div>
                            <div class="form-text">
                                <?= $isEdit ? 'Stand heute laut Sparplan – bei Abweichung einfach korrigieren.' : 'Liegt die erste Rate in der Vergangenheit, zählen die Raten seitdem als angespart.' ?>
                            </div>

                            <div class="alert mt-3 mb-0 py-2" :class="late ? 'alert-warning' : 'alert-info'" x-show="preview" style="display: none">
                                <i class="bi bi-flag"></i> <span x-text="preview"></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-light border small mb-0"><i class="bi bi-lock"></i> Der Sparplan liegt auf einem Konto, das du nicht sehen darfst, und bleibt unverändert.</div>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary btn-lg">Speichern</button>
                <a href="<?= e(url($isEdit ? "/wishes/{$w['id']}" : '/wishes')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
