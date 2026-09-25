<?php
/** Variablen: $period, $from, $to, $extra (HTML für weitere Filter), $action */
use App\Controllers\ReportController;

?>
<form method="get" action="<?= e(url($action)) ?>" class="card mb-3" x-data="{ period: '<?= e($period) ?>' }">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <select name="period" class="form-select w-auto" x-model="period" @change="period !== 'custom' && $el.form.submit()">
            <?php foreach (ReportController::PERIODS as $k => $l): ?><option value="<?= $k ?>" <?= selected($k, $period) ?>><?= e($l) ?></option><?php endforeach; ?>
        </select>
        <template x-if="period === 'custom'">
            <div class="d-flex gap-2">
                <input type="date" name="from" class="form-control" value="<?= e($from) ?>">
                <input type="date" name="to" class="form-control" value="<?= e($to) ?>">
            </div>
        </template>
        <?= $extra ?? '' ?>
        <button class="btn btn-primary"><i class="bi bi-funnel"></i> Anzeigen</button>
        <span class="small text-body-secondary ms-auto"><?= e(date_de($from)) ?> – <?= e(date_de($to)) ?></span>
    </div>
</form>
