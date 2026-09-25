<?php
use App\Core\Auth;
use App\Core\View;

$fieldLabels = ['payee' => 'Empfänger', 'purpose' => 'Verwendungszweck', 'any' => 'Empfänger oder Zweck', 'name' => 'Postenname'];
$opLabels = ['contains' => 'enthält', 'equals' => 'ist gleich', 'starts' => 'beginnt mit', 'regex' => 'passt auf (Regex)'];
?>
<p class="text-body-secondary small">
    Neue Buchungen (auch aus dem CSV-Import) und Einkaufsposten werden automatisch einer Kategorie zugeordnet.
    Reihenfolge: eigene Regeln (kleinste Priorität zuerst) → bisherige Zuordnungen beim gleichen Empfänger bzw. Produkt → eingebaute Stichwörter (z. B. REWE → Lebensmittel).
</p>

<?php if (!Auth::isChild()): ?>
    <form method="post" action="<?= e(url('/rules')) ?>" class="card mb-3" x-data="{ target: 'transaction' }">
        <?= csrf_field() ?>
        <div class="card-body">
            <h2 class="h6">Neue Regel</h2>
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label">Für</label>
                    <select class="form-select" name="target" x-model="target">
                        <option value="transaction">Buchungen</option>
                        <option value="item">Einkaufsposten</option>
                    </select>
                </div>
                <div class="col-6 col-md-2" x-show="target === 'transaction'">
                    <label class="form-label">Feld</label>
                    <select class="form-select" name="field">
                        <option value="any">Empfänger oder Zweck</option>
                        <option value="payee">Empfänger</option>
                        <option value="purpose">Verwendungszweck</option>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Bedingung</label>
                    <select class="form-select" name="operator">
                        <?php foreach ($opLabels as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Suchbegriff</label>
                    <input class="form-control" name="value" required placeholder="z. B. netflix">
                </div>
                <div class="col-8 col-md-3">
                    <label class="form-label">→ Kategorie</label>
                    <?= View::partial('partials/category_select', ['categories' => $categories, 'name' => 'category_id', 'attrs' => 'required']) ?>
                </div>
                <div class="col-4 col-md-1">
                    <label class="form-label">Prio.</label>
                    <input class="form-control" type="number" name="priority" value="100" min="1" max="999">
                </div>
            </div>
            <button class="btn btn-primary mt-3">Regel hinzufügen</button>
        </div>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-transparent d-flex align-items-center">
        <strong>Regeln</strong>
        <form method="post" action="<?= e(url('/rules/apply')) ?>" class="ms-auto">
            <?= csrf_field() ?>
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-magic"></i> Auf Buchungen ohne Kategorie anwenden</button>
        </form>
    </div>
    <?php if (!$rules): ?>
        <div class="card-body text-body-secondary small">Noch keine eigenen Regeln. Tipp: Beim Bearbeiten einer Buchung kannst du „Künftig automatisch so einordnen“ anhaken.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Für</th><th>Bedingung</th><th>Kategorie</th><th class="text-end">Prio.</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rules as $r): ?>
                    <tr>
                        <td class="small"><?= $r['target'] === 'item' ? 'Posten' : 'Buchung' ?><?= $r['learned'] ? ' <span class="badge text-bg-light">gelernt</span>' : '' ?></td>
                        <td><span class="text-body-secondary small"><?= e($fieldLabels[$r['field']]) ?> <?= e($opLabels[$r['operator']]) ?></span> <code><?= e($r['value']) ?></code></td>
                        <td><i class="bi bi-<?= e($r['category_icon']) ?>" style="color: <?= e($r['category_color']) ?>"></i> <?= e(($r['category_parent'] ? $r['category_parent'] . ' › ' : '') . $r['category_name']) ?></td>
                        <td class="text-end"><?= (int) $r['priority'] ?></td>
                        <td class="text-end">
                            <?php if (!Auth::isChild()): ?>
                                <form method="post" action="<?= e(url("/rules/{$r['id']}/delete")) ?>" onsubmit="return confirm('Regel löschen?')">
                                    <?= csrf_field() ?><button class="btn btn-sm btn-link text-danger" aria-label="Löschen"><i class="bi bi-trash"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
