<?php $isEdit = !empty($loan['id']); ?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($isEdit ? url("/loans/{$loan['id']}") : url('/loans')) ?>" class="card" x-data="{ type: '<?= e($loan['loan_type']) ?>' }">
            <?= csrf_field() ?>
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-6">
                        <label class="form-label" for="name">Bezeichnung</label>
                        <input class="form-control" id="name" name="name" value="<?= e($loan['name']) ?>" placeholder="z. B. Baufinanzierung Haus" required>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="lender">Bank</label>
                        <input class="form-control" id="lender" name="lender" value="<?= e($loan['lender']) ?>">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label" for="contract_number">Vertragsnr.</label>
                        <input class="form-control" id="contract_number" name="contract_number" value="<?= e($loan['contract_number']) ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Art</label>
                    <div class="btn-group w-100" role="group">
                        <input type="radio" class="btn-check" name="loan_type" id="t_ann" value="annuity" x-model="type">
                        <label class="btn btn-outline-primary" for="t_ann">Annuitätendarlehen <small class="d-none d-sm-inline">(gleiche Rate)</small></label>
                        <input type="radio" class="btn-check" name="loan_type" id="t_fix" value="fixed_principal" x-model="type">
                        <label class="btn btn-outline-primary" for="t_fix">Tilgungsdarlehen <small class="d-none d-sm-inline">(gleiche Tilgung)</small></label>
                    </div>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="principal">Kreditbetrag (€)</label>
                        <input class="form-control text-end" id="principal" name="principal" inputmode="decimal" value="<?= e($loan['principal']) ?>" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="interest_rate">Sollzins p. a. (%)</label>
                        <input class="form-control text-end" id="interest_rate" name="interest_rate" inputmode="decimal" value="<?= e($loan['interest_rate']) ?>" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="payout_date">Auszahlung am</label>
                        <input type="date" class="form-control" id="payout_date" name="payout_date" value="<?= e($loan['payout_date']) ?>" required>
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="monthly_payment" x-text="type === 'annuity' ? 'Monatsrate (€)' : 'Monatl. Tilgung (€)'">Monatsrate (€)</label>
                        <input class="form-control text-end" id="monthly_payment" name="monthly_payment" inputmode="decimal" value="<?= e($loan['monthly_payment']) ?>">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="initial_repayment_rate">oder anf. Tilgung (% p. a.)</label>
                        <input class="form-control text-end" id="initial_repayment_rate" name="initial_repayment_rate" inputmode="decimal" value="<?= e($loan['initial_repayment_rate']) ?>">
                    </div>
                    <div class="col-6 col-md-4">
                        <label class="form-label" for="first_payment_date">Erste Rate am</label>
                        <input type="date" class="form-control" id="first_payment_date" name="first_payment_date" value="<?= e($loan['first_payment_date']) ?>" required>
                    </div>
                </div>
                <div class="form-text mb-3">Ist die Monatsrate angegeben, wird sie verwendet. Sonst wird sie aus Zins + anfänglicher Tilgung berechnet.</div>

                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="fixed_until">Zinsbindung bis</label>
                        <input type="date" class="form-control" id="fixed_until" name="fixed_until" value="<?= e($loan['fixed_until']) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="account_id">Rate wird abgebucht von</label>
                        <select class="form-select" id="account_id" name="account_id">
                            <option value="">–</option>
                            <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $loan['account_id']) ?>><?= e($a['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="note">Notizen</label>
                    <textarea class="form-control" id="note" name="note" rows="2"><?= e($loan['note']) ?></textarea>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary">Speichern</button>
                <a href="<?= e(url($isEdit ? "/loans/{$loan['id']}" : '/loans')) ?>" class="btn btn-link">Abbrechen</a>
            </div>
        </form>
        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url("/loans/{$loan['id']}/delete")) ?>" class="mt-3 text-end" onsubmit="return confirm('Kredit samt Sondertilgungen löschen?')">
                <?= csrf_field() ?><button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Kredit löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
