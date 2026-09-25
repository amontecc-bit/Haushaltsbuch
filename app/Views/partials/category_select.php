<?php
/**
 * Auswahlfeld für Kategorien mit Gruppen.
 * Variablen: $categories (flach aus CategoryRepository::all), $name, $selected, $type (expense|income|null),
 *            $id, $class, $attrs (zusätzliche HTML-Attribute), $emptyLabel
 */
$type ??= null;
$selected ??= null;
$class ??= 'form-select';
$attrs ??= '';
$emptyLabel ??= '– ohne Kategorie –';
$groups = [];
foreach ($categories as $c) {
    if ($type && $c['type'] !== $type) {
        continue;
    }
    $key = $c['parent_id'] ?: $c['id'];
    if (!$c['parent_id']) {
        $groups[$key]['parent'] = $c;
    } else {
        $groups[$key]['children'][] = $c;
    }
}
$typeLabels = ['expense' => 'Ausgaben', 'income' => 'Einnahmen'];
$lastType = null;
?>
<select name="<?= e($name) ?>" <?= !empty($id) ? 'id="' . e($id) . '"' : '' ?> class="<?= e($class) ?>" <?= $attrs ?>>
    <option value=""><?= e($emptyLabel) ?></option>
    <?php foreach ($groups as $g): if (empty($g['parent'])) { continue; } $p = $g['parent']; ?>
        <?php if (!$type && $lastType !== $p['type']): $lastType = $p['type']; ?>
            <option disabled>── <?= e($typeLabels[$p['type']]) ?> ──</option>
        <?php endif; ?>
        <?php if (empty($g['children'])): ?>
            <option value="<?= (int) $p['id'] ?>" <?= selected($p['id'], $selected) ?>><?= e($p['name']) ?></option>
        <?php else: ?>
            <optgroup label="<?= e($p['name']) ?>">
                <option value="<?= (int) $p['id'] ?>" <?= selected($p['id'], $selected) ?>><?= e($p['name']) ?> (allgemein)</option>
                <?php foreach ($g['children'] as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= selected($c['id'], $selected) ?>><?= e($c['name']) ?></option>
                <?php endforeach; ?>
            </optgroup>
        <?php endif; ?>
    <?php endforeach; ?>
</select>
