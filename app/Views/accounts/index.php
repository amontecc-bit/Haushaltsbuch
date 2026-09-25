<?php use App\Core\Auth; ?>
<div class="card mb-3 stat-card">
    <div class="card-body d-flex align-items-center">
        <div>
            <div class="stat-label">Gesamtvermögen (aktive Konten)</div>
            <div class="stat-value <?= $total < 0 ? 'text-danger' : '' ?>"><?= money($total, true) ?></div>
        </div>
    </div>
</div>

<?php if (!$accounts): ?>
    <div class="empty-state card">
        <i class="bi bi-wallet2"></i>
        <p>Noch keine Konten vorhanden.</p>
        <?php if (Auth::isAdmin()): ?><a class="btn btn-primary" href="<?= e(url('/accounts/new')) ?>">Erstes Konto anlegen</a><?php endif; ?>
    </div>
<?php else: ?>
    <div class="row g-3">
        <?php foreach ($accounts as $a): ?>
            <div class="col-md-6 col-xl-4">
                <div class="card h-100 <?= $a['archived'] ? 'opacity-50' : '' ?>">
                    <div class="card-body d-flex gap-3">
                        <div class="acc-bar" style="background: <?= e($a['color']) ?>"></div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="fw-semibold"><?= e($a['name']) ?></div>
                                    <div class="small text-body-secondary"><?= e(account_type_label($a['type'])) ?><?= $a['bank'] ? ' · ' . e($a['bank']) : '' ?><?= $a['archived'] ? ' · archiviert' : '' ?></div>
                                </div>
                                <?php if (Auth::isAdmin()): ?>
                                    <a href="<?= e(url("/accounts/{$a['id']}/edit")) ?>" class="btn btn-sm btn-link text-body-secondary" aria-label="Bearbeiten"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                            </div>
                            <div class="fs-4 amount mt-2 <?= money_class($a['balance']) ?>"><?= money($a['balance']) ?></div>
                            <?php if ($a['iban']): ?><div class="small text-body-secondary font-monospace"><?= e(trim(chunk_split($a['iban'], 4, ' '))) ?></div><?php endif; ?>
                            <div class="mt-2 d-flex gap-2">
                                <a href="<?= e(url('/transactions', ['account_id' => $a['id']])) ?>" class="btn btn-sm btn-outline-primary">Buchungen</a>
                                <a href="<?= e(url('/import', ['account_id' => $a['id']])) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-upload"></i> CSV</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
