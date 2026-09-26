<?php $isEdit = !empty($scenario['id']); ?>
<div class="row justify-content-center">
    <div class="col-lg-8 col-xl-7">
        <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
        <form method="post" action="<?= e($isEdit ? url("/forecast/scenarios/{$scenario['id']}") : url('/forecast/scenarios')) ?>" class="card">
            <?= csrf_field() ?>
            <input type="hidden" name="avg" value="<?= (int) $avg ?>">
            <div class="card-body">
                <div class="row g-2 mb-3">
                    <div class="col-md-5">
                        <label class="form-label" for="name">Name</label>
                        <input class="form-control" id="name" name="name" value="<?= e($scenario['name']) ?>" placeholder="z. B. Sparsam, Realistisch, Urlaubsjahr" maxlength="100" required>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label" for="note">Notiz <span class="text-body-secondary small">(optional)</span></label>
                        <input class="form-control" id="note" name="note" value="<?= e($scenario['note'] ?? '') ?>" maxlength="255" placeholder="Annahmen, Stand …">
                    </div>
                </div>

                <h2 class="h6 mb-1">Variabler Saldo je Konto und Monat</h2>
                <p class="small text-body-secondary mb-2">
                    Alles, was nicht als Fixkosten eingeplant ist: Einkäufe, Tanken, Freizeit, aber auch unregelmäßige Einnahmen.
                    Ausgaben mit Minus eingeben (z. B. <code>-650</code>). <strong>Leeres Feld = berechneter Ø</strong>
                    <?= $avg ? '(aus den letzten ' . (int) $avg . ' vollen Monaten)' : '(derzeit „nicht berücksichtigen“ = 0 €)' ?>.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>Konto</th><th class="text-end">berechnet Ø</th><th class="text-end" style="width: 11rem">eigener Wert (€)</th></tr></thead>
                        <tbody>
                        <?php foreach ($accounts as $id => $a): ?>
                            <?php $calc = $computed[$id] ?? null; ?>
                            <tr>
                                <td><span style="color: <?= e($a['color']) ?>">●</span> <label for="amount<?= $id ?>" class="mb-0"><?= e($a['name']) ?></label></td>
                                <td class="text-end text-nowrap">
                                    <?php if ($calc !== null): ?>
                                        <span class="<?= money_class($calc) ?>"><?= money($calc) ?></span>
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1" title="Wert übernehmen"
                                                onclick="document.getElementById('amount<?= $id ?>').value = '<?= e(money_input($calc)) ?>'"><i class="bi bi-arrow-right-circle"></i></button>
                                    <?php else: ?>
                                        <span class="text-body-secondary small">keine Historie</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input class="form-control form-control-sm text-end" id="amount<?= $id ?>" name="amount[<?= $id ?>]" inputmode="decimal"
                                           value="<?= e(array_key_exists($id, $values) ? money_input($values[$id]) : '') ?>"
                                           placeholder="<?= e($calc !== null ? 'Ø ' . money_input($calc) : '0,00') ?>">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$accounts): ?><tr><td colspan="3" class="text-body-secondary small">Keine Konten in der Prognose.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-transparent d-flex gap-2">
                <button class="btn btn-primary">Speichern</button>
                <a href="<?= e(url('/forecast')) ?>" class="btn btn-link">Abbrechen</a>
                <?php if ($isEdit): ?>
                    <a href="<?= e(url('/forecast/scenarios/new', ['copy' => $scenario['id']])) ?>" class="btn btn-link ms-auto"><i class="bi bi-copy"></i> Als Kopie anlegen</a>
                <?php endif; ?>
            </div>
        </form>
        <?php if ($isEdit): ?>
            <form method="post" action="<?= e(url("/forecast/scenarios/{$scenario['id']}/delete")) ?>" class="mt-3 text-end" onsubmit="return confirm('Szenario löschen?')">
                <?= csrf_field() ?><button class="btn btn-link text-danger"><i class="bi bi-trash"></i> Szenario löschen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
