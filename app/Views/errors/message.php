<div class="empty-state">
    <i class="bi bi-exclamation-circle"></i>
    <h2 class="h5 text-body"><?= e($title ?? 'Hinweis') ?></h2>
    <p><?= e($message ?? '') ?></p>
    <a href="<?= e(url('/')) ?>" class="btn btn-outline-primary">Zur Übersicht</a>
</div>
