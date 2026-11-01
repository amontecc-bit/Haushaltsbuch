<?php $tab = $tab ?? 'list'; ?>
<ul class="nav nav-pills mb-3 gap-1">
    <li class="nav-item"><a class="nav-link <?= $tab === 'list' ? 'active' : '' ?>" href="<?= e(url('/wishes')) ?>"><i class="bi bi-gift"></i> Wünsche</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'plans' ? 'active' : '' ?>" href="<?= e(url('/wishes/plans')) ?>"><i class="bi bi-piggy-bank"></i> Sparpläne nach Posten</a></li>
</ul>
