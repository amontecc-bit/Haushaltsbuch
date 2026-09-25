<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Session;

$path = $request->path ?? '/';
$nav = [
    ['/', 'speedometer2', 'Übersicht'],
    ['/transactions', 'list-ul', 'Buchungen'],
    ['/purchases', 'basket', 'Einkäufe'],
    ['/recurring', 'arrow-repeat', 'Fixkosten'],
    ['/import', 'upload', 'CSV-Import'],
    ['/reports', 'pie-chart', 'Auswertungen'],
    ['/forecast', 'graph-up-arrow', 'Prognose'],
    ['/loans', 'bank', 'Kredite'],
];
$navSettings = [
    ['/accounts', 'wallet2', 'Konten'],
    ['/categories', 'tags', 'Kategorien'],
    ['/rules', 'magic', 'Regeln'],
    ['/settings', 'gear', 'Einstellungen'],
];
if (Auth::isAdmin()) {
    array_splice($navSettings, 3, 0, [['/users', 'people', 'Familie']]);
}
$moreActive = $path !== '/' && !str_starts_with($path, '/transactions') && !str_starts_with($path, '/purchases');
$flashes = Session::pullFlash();
?><!doctype html>
<html lang="de" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e(Csrf::token()) ?>">
    <meta name="base-url" content="<?= e(base_path()) ?>">
    <meta name="theme-color" content="#2a78d6">
    <title><?= e(($title ?? '') ? $title . ' · ' : '') ?>Haushaltsbuch</title>
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
    <link rel="apple-touch-icon" href="<?= e(asset('img/icon-192.png')) ?>">
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('theme'); } catch (e) { /* Speicher gesperrt */ }
            if (!t) { t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'; }
            document.documentElement.setAttribute('data-bs-theme', t);
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
    <script defer src="<?= e(asset('vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
    <script src="<?= e(asset('js/app.js')) ?>"></script>
    <?php foreach (($scripts ?? []) as $s): ?>
        <script src="<?= e(asset($s)) ?>"></script>
    <?php endforeach; ?>
    <script defer src="<?= e(asset('vendor/alpine/alpine.min.js')) ?>"></script>
</head>
<body>
<div class="app">
    <!-- Sidebar (Desktop) -->
    <aside class="sidebar d-none d-lg-flex flex-column">
        <a href="<?= e(url('/')) ?>" class="brand"><i class="bi bi-journal-bookmark-fill"></i> Haushaltsbuch</a>
        <nav class="nav flex-column">
            <?php foreach ($nav as [$href, $icon, $label]): ?>
                <a class="nav-link <?= nav_active($href, $path) ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= $icon ?>"></i> <?= e($label) ?></a>
            <?php endforeach; ?>
            <div class="nav-sep">Verwaltung</div>
            <?php foreach ($navSettings as [$href, $icon, $label]): ?>
                <a class="nav-link <?= nav_active($href, $path) ?>" href="<?= e(url($href)) ?>"><i class="bi bi-<?= $icon ?>"></i> <?= e($label) ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="mt-auto sidebar-user">
            <div class="small text-body-secondary"><i class="bi bi-person-circle"></i> <?= e(Auth::user()['name'] ?? '') ?></div>
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="HB.toggleTheme()" title="Hell/Dunkel"><i class="bi bi-circle-half"></i></button>
                <form method="post" action="<?= e(url('/logout')) ?>"><?= csrf_field() ?>
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-right"></i> Abmelden</button>
                </form>
            </div>
        </div>
    </aside>

    <div class="main">
        <!-- Kopfzeile (Mobil) -->
        <header class="topbar d-lg-none">
            <?php if (!empty($back)): ?>
                <a href="<?= e(url($back)) ?>" class="topbar-btn" aria-label="Zurück"><i class="bi bi-chevron-left"></i></a>
            <?php else: ?>
                <span class="topbar-btn"><i class="bi bi-journal-bookmark-fill text-primary"></i></span>
            <?php endif; ?>
            <div class="topbar-title"><?= e($title ?? 'Haushaltsbuch') ?></div>
            <button type="button" class="topbar-btn" onclick="HB.toggleTheme()" aria-label="Hell/Dunkel"><i class="bi bi-circle-half"></i></button>
        </header>

        <main class="content container-fluid">
            <?php if (!empty($title)): ?>
                <div class="page-head d-none d-lg-flex">
                    <h1 class="h3 mb-0"><?= e($title) ?></h1>
                    <div class="ms-auto d-flex gap-2"><?= $actions ?? '' ?></div>
                </div>
                <?php if (!empty($actions)): ?>
                    <div class="d-flex d-lg-none gap-2 mb-3 flex-wrap"><?= $actions ?></div>
                <?php endif; ?>
            <?php endif; ?>

            <?php foreach ($flashes as $f): ?>
                <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
                    <?= e($f['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>
                </div>
            <?php endforeach; ?>

            <?= $content ?>
        </main>
    </div>

    <!-- Untere Navigation (Mobil) -->
    <nav class="bottomnav d-lg-none">
        <a href="<?= e(url('/')) ?>" class="<?= nav_active('/', $path) ?>"><i class="bi bi-speedometer2"></i><span>Übersicht</span></a>
        <a href="<?= e(url('/transactions')) ?>" class="<?= nav_active('/transactions', $path) ?>"><i class="bi bi-list-ul"></i><span>Buchungen</span></a>
        <button type="button" class="fab" data-bs-toggle="offcanvas" data-bs-target="#quickAdd" aria-label="Neu"><i class="bi bi-plus-lg"></i></button>
        <a href="<?= e(url('/purchases')) ?>" class="<?= nav_active('/purchases', $path) ?>"><i class="bi bi-basket"></i><span>Einkäufe</span></a>
        <a href="<?= e(url('/more')) ?>" class="<?= $moreActive ? 'active' : '' ?>"><i class="bi bi-grid"></i><span>Mehr</span></a>
    </nav>

    <!-- Schnell-Aktionen -->
    <div class="offcanvas offcanvas-bottom quickadd" tabindex="-1" id="quickAdd">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Neu erfassen</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <div class="quick-grid">
                <a href="<?= e(url('/transactions/new', ['type' => 'expense'])) ?>"><i class="bi bi-dash-circle text-danger"></i>Ausgabe</a>
                <a href="<?= e(url('/transactions/new', ['type' => 'income'])) ?>"><i class="bi bi-plus-circle text-success"></i>Einnahme</a>
                <a href="<?= e(url('/transactions/new', ['type' => 'transfer'])) ?>"><i class="bi bi-arrow-left-right text-primary"></i>Umbuchung</a>
                <a href="<?= e(url('/purchases/new', ['mode' => 'photo'])) ?>"><i class="bi bi-camera text-warning"></i>Bon fotografieren</a>
                <a href="<?= e(url('/purchases/new', ['mode' => 'manual'])) ?>"><i class="bi bi-basket text-success"></i>Einkauf erfassen</a>
                <a href="<?= e(url('/purchases/new', ['mode' => 'pdf'])) ?>"><i class="bi bi-file-earmark-pdf text-danger"></i>PDF einlesen</a>
                <a href="<?= e(url('/import')) ?>"><i class="bi bi-filetype-csv text-info"></i>CSV-Import</a>
                <a href="<?= e(url('/recurring/new')) ?>"><i class="bi bi-arrow-repeat text-secondary"></i>Fixkosten</a>
            </div>
        </div>
    </div>
</div>
</body>
</html>
