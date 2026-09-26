<?php
use App\Core\View;

$isEdit = !empty($tpl['id']);
$action = $isEdit ? url("/recurring/{$tpl['id']}") : url('/recurring');
// Gegeneintrag: standardmäßig ein anderes Konto als das der Vorlage vorschlagen
$mainAccount = (int) ($tpl['account_id'] ?? $accounts[0]['id']);
$counterAccount = $request->int('counter_account_id')
    ?? (current(array_filter(array_column($accounts, 'id'), fn ($id) => (int) $id !== $mainAccount)) ?: null);
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($action) ?>" class="card" x-data="{ kind: '<?= e($tpl['kind']) ?>', counter: <?= $request->bool('counter') ? 'true' : 'false' ?> }">
            <?= csrf_field() ?>
            <input type="hidden" name="kind" :value="kind">
            <div class="card-body">
                <div class="btn-group type-toggle w-100 mb-3" role="group">
                    <button type="button" class="btn" :class="kind === 'expense' ? 'btn-danger' : 'btn-outline-danger'" @click="kind = 'expense'">Ausgabe</button>
                    <button type="button" class="btn" :class="kind === 'income' ? 'btn-success' : 'btn-outline-success'" @click="kind = 'income'">Einnahme</button>
                    <button type="button" class="btn" :class="kind === 'transfer' ? 'btn-primary' : 'btn-outline-primary'" @click="kind = 'transfer'">Umbuchung</button>
                </div>
                <div class="input-group mb-3">
                    <input class="form-control amount-input" name="amount" inputmode="decimal" placeholder="0,00" value="<?= e($tpl['amount']) ?>" required autofocus>
                    <span class="input-group-text fs-4">€</span>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="payee">Bezeichnung / Empfänger</label>
                    <input class="form-control" id="payee" name="payee" value="<?= e($tpl['payee']) ?>" placeholder="z. B. Miete, Netflix, Gehalt">
                </div>
                <div class="row g-2 mb-3">
                    <div :class="kind === 'transfer' ? 'col-6' : 'col-12'">
                        <label class="form-label" for="account_id" x-text="kind === 'transfer' ? 'Von Konto' : 'Konto'">Konto</label>
                        <select class="form-select" id="account_id" name="account_id">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $tpl['account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6" x-show="kind === 'transfer'" x-cloak>
                        <label class="form-label" for="to_account_id">Auf Konto</label>
                        <select class="form-select" id="to_account_id" name="to_account_id">
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $tpl['to_account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-3" x-show="kind !== 'transfer'">
                    <label class="form-label" for="category_id">Kategorie</label>
                    <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'selected' => $tpl['category_id'], 'id' => 'category_id']) ?>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-12 col-sm-4">
                        <label class="form-label" for="interval">Intervall</label>
                        <select class="form-select" id="interval" name="interval">
                            <?php foreach (['monthly', 'quarterly', 'halfyearly', 'yearly'] as $i): ?>
                                <option value="<?= $i ?>" <?= selected($i, $tpl['interval']) ?>><?= e(interval_label($i)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label" for="start_date">Erste Fälligkeit</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?= e($tpl['start_date']) ?>" required>
                    </div>
                    <div class="col-6 col-sm-4">
                        <label class="form-label" for="end_date">Endet am</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?= e($tpl['end_date']) ?>">
                    </div>
                </div>
                <div class="form-text mb-3">Der Tag der ersten Fälligkeit bestimmt den Buchungstag (z. B. 31. → in kürzeren Monaten der Monatsletzte).</div>
                <div class="mb-3">
                    <label class="form-label" for="purpose">Verwendungszweck / Notiz</label>
                    <input class="form-control" id="purpose" name="purpose" value="<?= e($tpl['purpose']) ?>">
                </div>
                <?php if ($counterpart): ?>
                    <div class="alert alert-light border small mb-3" x-show="kind !== 'transfer'">
                        <i class="bi bi-arrow-left-right"></i>
                        Gegeneintrag: <a href="<?= e(url("/recurring/{$counterpart['id']}/edit")) ?>"><?= e($counterpart['payee'] ?: 'Eintrag') ?></a>
                        auf <strong><?= e($counterpart['account_name']) ?></strong> (<?= money($counterpart['amount']) ?>)
                        <div class="form-check mt-1">
                            <input class="form-check-input" type="checkbox" id="counter_sync" name="counter_sync" value="1" checked>
                            <label class="form-check-label" for="counter_sync">Änderungen an Betrag, Bezeichnung, Terminen und „Aktiv“ auf den Gegeneintrag übertragen</label>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="mb-3" x-show="kind !== 'transfer'">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" id="counter" name="counter" value="1" x-model="counter">
                            <label class="form-check-label" for="counter">Gegeneintrag auf anderem Konto anlegen</label>
                            <div class="form-text">Legt denselben Betrag mit umgekehrtem Vorzeichen auf einem anderen Konto an – z. B. Haushaltsgeld: Ausgabe hier, Einnahme auf dem Haushaltskonto.</div>
                        </div>
                        <div class="row g-2 mt-1" x-show="counter" x-cloak>
                            <div class="col-sm-6">
                                <label class="form-label" for="counter_account_id">Konto des Gegeneintrags</label>
                                <select class="form-select" id="counter_account_id" name="counter_account_id">
                                    <?php foreach ($accounts as $a): ?>
                                        <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $counterAccount) ?>><?= e($a['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <label class="form-label" for="counter_category_id">Kategorie des Gegeneintrags</label>
                                <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'counter_category_id', 'selected' => $request->int('counter_category_id'), 'id' => 'counter_category_id']) ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="auto_book" name="auto_book" value="1" <?= checked($tpl['auto_book']) ?>>
                    <label class="form-check-label" for="auto_book">Automatisch buchen, wenn fällig</label>
                    <div class="form-text">Aus: nur für die Prognose (z. B. wenn die Buchungen per CSV-Import kommen – sie werden dann automatisch zugeordnet).</div>
                </div>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch" id="active" name="active" value="1" <?= checked($tpl['active']) ?>>
                    <label class="form-check-label" for="active">Aktiv</label>
                </div>
                <?php if (!$isEdit): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="book_past" name="book_past" value="1" checked>
                        <label class="form-check-label" for="book_past">Termine in der Vergangenheit (ab erster Fälligkeit) nachbuchen</label>
                    </div>
                <?php elseif ($tpl['last_booked_date']): ?>
                    <div class="small text-body-secondary">Zuletzt gebucht: <?= e(date_de($tpl['last_booked_date'])) ?></div>
                <?php endif; ?>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary btn-lg">Speichern</button>
                <a href="<?= e(url('/recurring')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url("/recurring/{$tpl['id']}/delete")) ?>" class="mt-3 text-end" onsubmit="return HB.confirmSubmit(this, 'Wiederkehrende Buchung löschen? Bereits gebuchte Einträge bleiben erhalten.')">
                <?= csrf_field() ?>
                <button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
