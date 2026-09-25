<?php
$isEdit = !empty($account['id']);
$action = $isEdit ? url("/accounts/{$account['id']}") : url('/accounts');
?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-6">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($action) ?>" class="card">
            <div class="card-body">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control form-control-lg" id="name" name="name" value="<?= e($account['name']) ?>" placeholder="z. B. Girokonto Sparkasse" required autofocus>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="type">Art</label>
                        <select class="form-select" id="type" name="type">
                            <?php foreach (['giro', 'savings', 'cash', 'credit_card', 'other'] as $t): ?>
                                <option value="<?= $t ?>" <?= selected($t, $account['type']) ?>><?= e(account_type_label($t)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="bank">Bank</label>
                        <input class="form-control" id="bank" name="bank" value="<?= e($account['bank']) ?>">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="iban">IBAN <span class="text-body-secondary small">(optional, hilft beim CSV-Import)</span></label>
                    <input class="form-control font-monospace" id="iban" name="iban" value="<?= e($account['iban']) ?>" autocomplete="off">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label" for="opening_balance">Anfangssaldo (€)</label>
                        <input class="form-control text-end" id="opening_balance" name="opening_balance" inputmode="decimal" value="<?= e(money_input($account['opening_balance'])) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="opening_date">Stand vom</label>
                        <input type="date" class="form-control" id="opening_date" name="opening_date" value="<?= e($account['opening_date']) ?>">
                    </div>
                </div>
                <div class="form-text mb-3">Saldo am angegebenen Tag <em>vor</em> allen erfassten Buchungen. Tipp: Datum des ältesten Kontoauszugs, den du importierst.</div>
                <div class="d-flex gap-4 align-items-center mb-3 flex-wrap">
                    <div>
                        <label class="form-label d-block" for="color">Farbe</label>
                        <input type="color" class="form-control form-control-color" id="color" name="color" value="<?= e($account['color']) ?>">
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="include_in_forecast" name="include_in_forecast" value="1" <?= checked($account['include_in_forecast']) ?>>
                        <label class="form-check-label" for="include_in_forecast">In Prognose</label>
                    </div>
                    <?php if ($isEdit): ?>
                        <div class="form-check form-switch mt-3">
                            <input class="form-check-input" type="checkbox" role="switch" id="archived" name="archived" value="1" <?= checked($account['archived']) ?>>
                            <label class="form-check-label" for="archived">Archiviert</label>
                        </div>
                    <?php endif; ?>
                </div>

                <h2 class="h6 mt-4">Wer darf dieses Konto nutzen?</h2>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Person</th><th class="text-center">Sehen</th><th class="text-center">Buchen</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u): $uid = (int) $u['id']; $p = $perms[$uid] ?? ['can_view' => false, 'can_book' => false]; ?>
                            <tr>
                                <td><?= e($u['name']) ?> <span class="badge text-bg-light"><?= e(role_label($u['role'])) ?></span></td>
                                <?php if ($u['role'] === 'admin'): ?>
                                    <td colspan="2" class="text-center small text-body-secondary">hat immer vollen Zugriff</td>
                                <?php else: ?>
                                    <td class="text-center"><input class="form-check-input" type="checkbox" name="perm_view[<?= $uid ?>]" value="1" <?= checked($p['can_view']) ?>></td>
                                    <td class="text-center"><input class="form-check-input" type="checkbox" name="perm_book[<?= $uid ?>]" value="1" <?= checked($p['can_book']) ?>></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex gap-2">
                <button class="btn btn-primary">Speichern</button>
                <a href="<?= e(url('/accounts')) ?>" class="btn btn-outline-secondary">Abbrechen</a>
            </div>
        </form>
        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url("/accounts/{$account['id']}/delete")) ?>" class="mt-3 text-end" onsubmit="return HB.confirmSubmit(this, 'Konto löschen? Konten mit Buchungen werden nur archiviert.')">
                <?= csrf_field() ?>
                <button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Konto löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
