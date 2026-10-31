<?php use App\Core\View; ?>
<div class="row justify-content-center">
    <div class="col-lg-7 col-xl-5">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e(url("/calendar/{$event['id']}/book", ['date' => $date])) ?>" class="card">
            <?= csrf_field() ?>
            <div class="card-body">
                <p class="mb-3"><i class="bi bi-calendar-event"></i> <strong><?= e($event['title']) ?></strong> am <?= e(date_de($date)) ?>
                    <br><span class="small text-body-secondary">Geplantes Budget: <?= money($budget['amount']) ?> – der Betrag lässt sich hier noch anpassen.</span></p>
                <label class="form-label" for="amount">Ausgabe</label>
                <div class="input-group mb-3">
                    <input class="form-control amount-input" id="amount" name="amount" inputmode="decimal" value="<?= e($input['amount']) ?>" required autofocus>
                    <span class="input-group-text fs-4">€</span>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="account_id">Konto</label>
                        <select class="form-select" id="account_id" name="account_id" required>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= (int) $a['id'] ?>" <?= selected($a['id'], $input['account_id']) ?>><?= e($a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="booking_date">Buchungsdatum</label>
                        <input type="date" class="form-control" id="booking_date" name="booking_date" value="<?= e($input['booking_date']) ?>" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="category_id">Kategorie</label>
                    <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'selected' => $input['category_id'], 'id' => 'category_id']) ?>
                </div>
                <div class="mb-1">
                    <label class="form-label" for="note">Notiz</label>
                    <input class="form-control" id="note" name="note" maxlength="120" value="<?= e($input['note']) ?>">
                </div>
                <?php if (!$accounts): ?><div class="alert alert-warning small mt-3 mb-0">Du hast auf kein Konto Buchungsrechte.</div><?php endif; ?>
            </div>
            <div class="card-footer bg-transparent d-grid d-sm-flex gap-2">
                <button class="btn btn-primary btn-lg" <?= $accounts ? '' : 'disabled' ?>><i class="bi bi-cash-coin"></i> Als Ausgabe buchen</button>
                <a class="btn btn-outline-secondary" href="<?= e(url($back)) ?>">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
