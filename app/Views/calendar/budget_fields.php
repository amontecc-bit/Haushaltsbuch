<?php
/**
 * Budgetfelder (eigenes Budget an einem Termin).
 * Variablen: $budget (oder null), $accounts (bebuchbare Konten), $categories, $defaultAccount (Vorzugskonto oder null)
 */
use App\Core\View;

$accountId = $budget['account_id'] ?? $defaultAccount;
?>
<div class="row g-2 mb-2">
    <div class="col-sm-5">
        <label class="form-label small" for="budget_amount">Betrag</label>
        <div class="input-group">
            <input class="form-control" id="budget_amount" name="budget_amount" inputmode="decimal" placeholder="0,00"
                   value="<?= e(isset($budget['amount']) ? money_input($budget['amount']) : '') ?>" x-model="budget">
            <span class="input-group-text">€</span>
        </div>
    </div>
    <div class="col-sm-7">
        <label class="form-label small" for="budget_account_id">Konto (für Prognose und Buchung)</label>
        <select class="form-select" id="budget_account_id" name="budget_account_id" x-model="budgetAccount">
            <option value="">– ohne Konto –</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $accountId) ?>><?= e($a['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-sm-6">
        <label class="form-label small" for="budget_category_id">Kategorie</label>
        <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'budget_category_id', 'selected' => $budget['category_id'] ?? null,
            'id' => 'budget_category_id', 'attrs' => 'x-model="budgetCategory"']) ?>
    </div>
    <div class="col-sm-6">
        <label class="form-label small" for="budget_note">Notiz</label>
        <input class="form-control" id="budget_note" name="budget_note" maxlength="255" value="<?= e($budget['note'] ?? '') ?>" placeholder="z. B. Eintritt, Tanken">
    </div>
</div>
