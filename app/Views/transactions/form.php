<?php
use App\Core\View;

$isEdit = !empty($tx['id']);
$action = $isEdit ? url("/transactions/{$tx['id']}") : url('/transactions');
$init = [
    'kind'       => $tx['kind'],
    'categoryId' => (string) ($tx['category_id'] ?? ''),
    'payee'      => (string) ($tx['payee'] ?? ''),
    'repeat'     => false,
];
// Nach dem Bearbeiten zur gefilterten Liste zurückkehren
$return = '';
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($isEdit && $ref && parse_url($ref, PHP_URL_PATH) === url('/transactions')) {
    $return = '/transactions?' . (parse_url($ref, PHP_URL_QUERY) ?? '');
}
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <?php if ($isEdit && !empty($tx['recurring_id'])): ?>
            <div class="alert alert-info small"><i class="bi bi-arrow-repeat"></i>
                <?= ($tx['source'] ?? '') === 'recurring' ? 'Diese Buchung wurde aus den Fixkosten' : 'Diese Buchung gehört zu den Fixkosten' ?>
                <a href="<?= e(url("/recurring/{$tx['recurring_id']}/edit")) ?>"><?= e($tx['recurring_payee'] ?: 'wiederkehrende Buchung') ?></a><?= ($tx['source'] ?? '') === 'recurring' ? ' erzeugt.' : '.' ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= e($action) ?>" x-data="txForm(<?= e(json_encode($init)) ?>)" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" :value="kind">
            <div class="card-body">
                <div class="btn-group type-toggle w-100 mb-3" role="group" aria-label="Art der Buchung">
                    <button type="button" class="btn" :class="kind === 'expense' ? 'btn-danger' : 'btn-outline-danger'" @click="kind = 'expense'"><i class="bi bi-dash-circle"></i> Ausgabe</button>
                    <button type="button" class="btn" :class="kind === 'income' ? 'btn-success' : 'btn-outline-success'" @click="kind = 'income'"><i class="bi bi-plus-circle"></i> Einnahme</button>
                    <button type="button" class="btn" :class="kind === 'transfer' ? 'btn-primary' : 'btn-outline-primary'" @click="kind = 'transfer'"><i class="bi bi-arrow-left-right"></i> Umbuchung</button>
                </div>

                <div class="mb-3">
                    <label class="form-label visually-hidden" for="amount">Betrag</label>
                    <div class="input-group">
                        <input class="form-control amount-input" :class="kind === 'expense' ? 'text-danger' : (kind === 'income' ? 'text-success' : '')"
                               id="amount" name="amount" inputmode="decimal" autocomplete="off" placeholder="0,00" value="<?= e($tx['amount']) ?>" required <?= $isEdit ? '' : 'autofocus' ?>>
                        <span class="input-group-text fs-4">€</span>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div :class="kind === 'transfer' ? 'col-6' : 'col-12'">
                        <label class="form-label" for="account_id" x-text="kind === 'transfer' ? 'Von Konto' : 'Konto'">Konto</label>
                        <select class="form-select" id="account_id" name="account_id">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $tx['account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6" x-show="kind === 'transfer'" x-cloak>
                        <label class="form-label" for="to_account_id">Auf Konto</label>
                        <select class="form-select" id="to_account_id" name="to_account_id">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $tx['to_account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mb-3" x-show="kind !== 'transfer'">
                    <label class="form-label" for="category_id">Kategorie <span class="badge text-bg-warning" x-show="suggested" x-cloak>Vorschlag</span></label>
                    <template x-if="kind === 'expense'">
                        <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'selected' => $tx['category_id'], 'type' => 'expense', 'id' => 'category_id', 'attrs' => 'x-model="categoryId" @change="suggested=false"']) ?>
                    </template>
                    <template x-if="kind === 'income'">
                        <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'selected' => $tx['category_id'], 'type' => 'income', 'id' => 'category_id', 'attrs' => 'x-model="categoryId" @change="suggested=false"']) ?>
                    </template>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-5">
                        <label class="form-label" for="booking_date">Datum</label>
                        <input type="date" class="form-control" id="booking_date" name="booking_date" value="<?= e($tx['booking_date']) ?>" required>
                    </div>
                    <div class="col-7">
                        <label class="form-label" for="payee" x-text="kind === 'income' ? 'Von' : (kind === 'transfer' ? 'Beschreibung' : 'Empfänger / Geschäft')">Empfänger / Geschäft</label>
                        <input class="form-control" id="payee" name="payee" list="payee-list" x-model="payee" @change="suggest()" autocomplete="off">
                        <datalist id="payee-list">
                            <?php foreach ($payees as $p): ?><option value="<?= e($p['payee']) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                </div>

                <details class="mb-3" <?= ($tx['purpose'] || $tx['note']) ? 'open' : '' ?>>
                    <summary class="small text-body-secondary mb-2">Weitere Angaben</summary>
                    <div class="mb-2">
                        <label class="form-label" for="purpose">Verwendungszweck</label>
                        <textarea class="form-control" id="purpose" name="purpose" rows="2"><?= e($tx['purpose']) ?></textarea>
                    </div>
                    <div>
                        <label class="form-label" for="note">Notiz</label>
                        <input class="form-control" id="note" name="note" value="<?= e($tx['note']) ?>">
                    </div>
                </details>

                <?php if (!$isEdit): ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="repeat" name="repeat" value="1" x-model="repeat">
                        <label class="form-check-label" for="repeat"><i class="bi bi-arrow-repeat"></i> Wiederholen (Dauerauftrag / Fixkosten)</label>
                    </div>
                    <div class="row g-2 mb-3 ps-4" x-show="repeat" x-cloak>
                        <div class="col-6">
                            <label class="form-label" for="interval">Intervall</label>
                            <select class="form-select" id="interval" name="interval">
                                <option value="monthly">monatlich</option>
                                <option value="quarterly">vierteljährlich</option>
                                <option value="halfyearly">halbjährlich</option>
                                <option value="yearly">jährlich</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="end_date">Endet am <span class="text-body-secondary">(optional)</span></label>
                            <input type="date" class="form-control" id="end_date" name="end_date">
                        </div>
                    </div>
                <?php endif; ?>

                <div class="form-check mb-1" x-show="kind !== 'transfer' && payee && categoryId" x-cloak>
                    <input class="form-check-input" type="checkbox" id="create_rule" name="create_rule" value="1">
                    <label class="form-check-label small" for="create_rule">Künftig alle Buchungen von „<span x-text="payee"></span>“ automatisch so einordnen</label>
                </div>
                <input type="hidden" name="return" value="<?= e($return) ?>">
            </div>
            <div class="card-footer d-flex gap-2 flex-wrap">
                <button class="btn btn-primary btn-lg flex-grow-1 flex-sm-grow-0">Speichern</button>
                <?php if (!$isEdit): ?>
                    <button class="btn btn-outline-primary btn-lg" name="another" value="1" x-show="!repeat">Speichern &amp; weitere</button>
                <?php endif; ?>
                <a href="<?= e(url('/transactions')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>

        <?php if ($isEdit && empty($tx['recurring_id'])): ?>
            <form method="post" action="<?= e(url("/transactions/{$tx['id']}/recurring")) ?>" class="card mt-3">
                <?= csrf_field() ?>
                <div class="card-body">
                    <h6 class="card-title"><i class="bi bi-arrow-repeat"></i> Als Fixkosten übernehmen</h6>
                    <p class="small text-body-secondary mb-2">Legt eine wiederkehrende Buchung mit Betrag, Konto und Kategorie dieser Buchung an (ab dem nächsten Termin). Vorhandene passende Buchungen werden als Fixkosten gekennzeichnet.</p>
                    <div class="d-flex flex-wrap gap-2 align-items-center">
                        <select class="form-select w-auto" name="interval" aria-label="Intervall">
                            <?php foreach (['monthly', 'quarterly', 'halfyearly', 'yearly'] as $i): ?>
                                <option value="<?= $i ?>"><?= e(interval_label($i)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" role="switch" id="rec_auto_book" name="auto_book" value="1" <?= checked(($tx['source'] ?? '') !== 'csv') ?>>
                            <label class="form-check-label" for="rec_auto_book">automatisch buchen</label>
                        </div>
                        <button class="btn btn-outline-primary">Übernehmen</button>
                    </div>
                    <div class="form-text">Aus = nur Prognose, z. B. wenn die Buchung jeden Monat per CSV-Import kommt.</div>
                </div>
            </form>
        <?php endif; ?>

        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url("/transactions/{$tx['id']}/delete")) ?>" class="mt-3 text-end" onsubmit="return HB.confirmSubmit(this, 'Buchung löschen?')">
                <?= csrf_field() ?>
                <button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Buchung löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
    function txForm(init) {
        return {
            kind: init.kind,
            categoryId: init.categoryId,
            payee: init.payee,
            repeat: init.repeat,
            suggested: false,
            async suggest() {
                if (!this.payee || (this.categoryId && !this.suggested)) return;
                try {
                    const r = await HB.post('/transactions/suggest', { payee: this.payee, purpose: document.getElementById('purpose').value });
                    if (r.category_id) {
                        this.categoryId = String(r.category_id);
                        this.suggested = true;
                    }
                } catch (e) { /* kein Vorschlag */ }
            },
        };
    }
</script>
