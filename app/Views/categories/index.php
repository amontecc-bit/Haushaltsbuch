<?php
use App\Core\View;

$byType = ['expense' => [], 'income' => []];
foreach ($tree as $t) {
    $byType[$t['type']][] = $t;
}
$flat = [];
foreach ($tree as $t) {
    $flat[] = $t;
    foreach ($t['children'] as $c) {
        $flat[] = $c + ['parent_name' => $t['name']];
    }
}
?>
<div x-data="catPage()">
    <?php if ($isAdmin): ?>
        <div class="mb-3 d-flex gap-2">
            <button class="btn btn-primary" @click="open(null)"><i class="bi bi-plus-lg"></i> Neue Kategorie</button>
            <a class="btn btn-outline-secondary" href="<?= e(url('/rules')) ?>"><i class="bi bi-magic"></i> Regeln für automatische Zuordnung</a>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <?php foreach (['expense' => 'Ausgaben', 'income' => 'Einnahmen'] as $type => $label): ?>
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header bg-transparent"><strong><?= e($label) ?></strong></div>
                    <div class="list-group list-group-flush tx-list">
                        <?php foreach ($byType[$type] as $p): ?>
                            <div class="list-group-item">
                                <?= View::partial('partials/category_badge', ['icon' => $p['icon'], 'color' => $p['color']]) ?>
                                <div class="tx-main">
                                    <div class="tx-title"><?= e($p['name']) ?></div>
                                    <?php if ($p['children']): ?>
                                        <div class="d-flex flex-wrap gap-1 mt-1">
                                            <?php foreach ($p['children'] as $c): ?>
                                                <?php if ($isAdmin): ?>
                                                    <button type="button" class="badge rounded-pill text-bg-light border" @click='open(<?= e(json_encode($c)) ?>)'><?= e($c['name']) ?></button>
                                                <?php else: ?>
                                                    <span class="badge rounded-pill text-bg-light border"><?= e($c['name']) ?></span>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($isAdmin): ?>
                                    <button type="button" class="btn btn-sm btn-link text-body-secondary" @click='open(<?= e(json_encode(array_diff_key($p, ['children' => 1]))) ?>)' aria-label="Bearbeiten"><i class="bi bi-pencil"></i></button>
                                    <button type="button" class="btn btn-sm btn-link text-body-secondary" @click='open({parent_id: <?= (int) $p['id'] ?>, type: "<?= e($p['type']) ?>", color: "<?= e($p['color']) ?>", icon: "<?= e($p['icon']) ?>"})' aria-label="Unterkategorie"><i class="bi bi-plus"></i></button>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($isAdmin): ?>
        <div class="modal fade" id="catModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form method="post" :action="form.id ? '<?= e(url('/categories')) ?>/' + form.id : '<?= e(url('/categories')) ?>'" class="modal-content">
                    <?= csrf_field() ?>
                    <div class="modal-header">
                        <h5 class="modal-title" x-text="form.id ? 'Kategorie bearbeiten' : 'Neue Kategorie'"></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Name</label>
                            <input class="form-control" name="name" x-model="form.name" required>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <label class="form-label">Art</label>
                                <select class="form-select" name="type" x-model="form.type" :disabled="!!form.parent_id">
                                    <option value="expense">Ausgabe</option>
                                    <option value="income">Einnahme</option>
                                </select>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Unterkategorie von</label>
                                <select class="form-select" name="parent_id" x-model="form.parent_id">
                                    <option value="">– Hauptkategorie –</option>
                                    <?php foreach ($tree as $p): ?>
                                        <option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Farbe</label>
                            <input type="color" class="form-control form-control-color" name="color" x-model="form.color">
                        </div>
                        <label class="form-label">Symbol</label>
                        <input type="hidden" name="icon" :value="form.icon">
                        <div class="d-flex flex-wrap gap-1">
                            <?php foreach ($icons as $i): ?>
                                <button type="button" class="btn btn-sm" :class="form.icon === '<?= $i ?>' ? 'btn-primary' : 'btn-outline-secondary'" @click="form.icon = '<?= $i ?>'"><i class="bi bi-<?= $i ?>"></i></button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link text-danger me-auto" x-show="form.id" @click="del()"><i class="bi bi-trash"></i> Löschen</button>
                        <button class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>

        <form method="post" id="delForm" class="d-none">
            <?= csrf_field() ?>
            <input type="hidden" name="move_to" id="moveTo">
        </form>

        <div class="modal fade" id="delModal" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header"><h5 class="modal-title">Kategorie löschen</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p>Buchungen und Posten dieser Kategorie verschieben nach:</p>
                        <select class="form-select" id="moveSelect">
                            <option value="">– übergeordnete Kategorie bzw. ohne Kategorie –</option>
                            <?php foreach ($flat as $c): ?>
                                <option value="<?= (int) $c['id'] ?>"><?= e(!empty($c['parent_name']) ? $c['parent_name'] . ' › ' . $c['name'] : $c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="small text-body-secondary mt-2">Unterkategorien einer gelöschten Hauptkategorie werden zu Hauptkategorien.</p>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-danger" @click="confirmDel()">Löschen</button></div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    function catPage() {
        return {
            form: {},
            open(cat) {
                this.form = Object.assign({ id: null, name: '', type: 'expense', parent_id: '', color: '#6c757d', icon: 'tag' }, cat || {});
                this.form.parent_id = this.form.parent_id ? String(this.form.parent_id) : '';
                bootstrap.Modal.getOrCreateInstance(document.getElementById('catModal')).show();
            },
            del() {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('catModal')).hide();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('delModal')).show();
            },
            confirmDel() {
                const f = document.getElementById('delForm');
                f.action = HB.url('/categories/' + this.form.id + '/delete');
                document.getElementById('moveTo').value = document.getElementById('moveSelect').value;
                f.submit();
            },
        };
    }
</script>
