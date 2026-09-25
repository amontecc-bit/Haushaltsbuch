<div class="more-grid mb-4">
    <a href="<?= e(url('/recurring')) ?>"><i class="bi bi-arrow-repeat"></i>Fixkosten</a>
    <a href="<?= e(url('/import')) ?>"><i class="bi bi-upload"></i>CSV-Import</a>
    <a href="<?= e(url('/reports')) ?>"><i class="bi bi-pie-chart"></i>Auswertungen</a>
    <a href="<?= e(url('/reports/items')) ?>"><i class="bi bi-receipt"></i>Einzelposten</a>
    <a href="<?= e(url('/forecast')) ?>"><i class="bi bi-graph-up-arrow"></i>Prognose</a>
    <a href="<?= e(url('/loans')) ?>"><i class="bi bi-bank"></i>Kredite</a>
    <a href="<?= e(url('/accounts')) ?>"><i class="bi bi-wallet2"></i>Konten</a>
    <a href="<?= e(url('/categories')) ?>"><i class="bi bi-tags"></i>Kategorien</a>
    <a href="<?= e(url('/rules')) ?>"><i class="bi bi-magic"></i>Regeln</a>
    <?php if ($isAdmin): ?><a href="<?= e(url('/users')) ?>"><i class="bi bi-people"></i>Familie</a><?php endif; ?>
    <a href="<?= e(url('/settings')) ?>"><i class="bi bi-gear"></i>Einstellungen</a>
</div>
<form method="post" action="<?= e(url('/logout')) ?>" class="text-center">
    <?= csrf_field() ?>
    <button class="btn btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Abmelden (<?= e($user['name']) ?>)</button>
</form>
