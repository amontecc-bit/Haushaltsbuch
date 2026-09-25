<?php
use App\Core\Session;

$flashes = Session::pullFlash();
?><!doctype html>
<html lang="de" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2a78d6">
    <title><?= e($title ?? 'Haushaltsbuch') ?> · Haushaltsbuch</title>
    <link rel="manifest" href="<?= e(url('/manifest.webmanifest')) ?>">
    <link rel="icon" href="<?= e(asset('img/icon.svg')) ?>" type="image/svg+xml">
    <script>
        (function () {
            var t = null;
            try { t = localStorage.getItem('theme'); } catch (e) { /* Speicher gesperrt */ }
            document.documentElement.setAttribute('data-bs-theme', t || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'));
        })();
    </script>
    <link rel="stylesheet" href="<?= e(asset('vendor/bootstrap/bootstrap.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('vendor/icons/bootstrap-icons.min.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="guest">
<main class="guest-box">
    <div class="text-center mb-4">
        <i class="bi bi-journal-bookmark-fill text-primary display-4"></i>
        <h1 class="h4 mt-2">Haushaltsbuch</h1>
    </div>
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
    <?= $content ?>
</main>
</body>
</html>
