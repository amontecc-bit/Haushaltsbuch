<?php
/** Variablen: $icon, $color, $size ('sm' optional) */
$icon = $icon ?: 'question';
$color = $color ?: '#adb5bd';
?><span class="cat-dot <?= e($size ?? '') ?>" style="background: <?= e($color) ?>"><i class="bi bi-<?= e($icon) ?>"></i></span>
