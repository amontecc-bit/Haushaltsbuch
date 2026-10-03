<?php
$fmt = function (?float $v): string {
    return $v === null ? '' : number_format($v, 2, ',', '.') . ' €';
};
$groups = [];
foreach ($entries as $e) {
    $groups[$e['group']][] = $e;
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($list['name']) ?> · Einkaufsliste</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { font: 11pt/1.35 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; margin: 0; padding: 16px; background: #fff; }
        .toolbar { display: flex; gap: 8px; margin-bottom: 16px; }
        .toolbar button, .toolbar a { font: inherit; padding: 6px 14px; border: 1px solid #2a78d6; border-radius: 6px; background: #2a78d6; color: #fff; text-decoration: none; cursor: pointer; }
        .toolbar a { background: #fff; color: #2a78d6; }
        h1 { font-size: 17pt; margin: 0 0 2px; }
        .meta { color: #555; font-size: 9.5pt; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; color: #555; border-bottom: 1.5px solid #111; padding: 4px 6px; }
        td { padding: 6px; border-bottom: 1px solid #ccc; vertical-align: top; }
        tr { break-inside: avoid; }
        .group td { font-weight: 600; font-size: 9.5pt; color: #333; background: #f1f1f1; border-bottom: 1px solid #999; padding-top: 8px; }
        .box { width: 22px; }
        .box span { display: inline-block; width: 13px; height: 13px; border: 1.5px solid #111; border-radius: 2px; margin-top: 2px; }
        .qty { width: 70px; white-space: nowrap; font-weight: 600; }
        .price { width: 150px; text-align: right; white-space: nowrap; }
        .sub { color: #555; font-size: 8.5pt; }
        .empty { color: #555; padding: 24px 0; }
        @media print { body { padding: 0; } .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Drucken / als PDF speichern</button>
    <a href="<?= e(url("/shopping/{$list['id']}")) ?>">Zurück zur Liste</a>
</div>

<h1><?= e($list['name']) ?></h1>
<div class="meta">Einkaufsliste · <?= count($entries) ?> Posten · Stand <?= e(date_de(date('Y-m-d'))) ?></div>

<?php if (!$entries): ?>
    <p class="empty">Keine offenen Posten.</p>
<?php else: ?>
    <table>
        <thead><tr><th class="box"></th><th class="qty">Menge</th><th>Posten</th><th class="price">Preis bisher (min – max)</th></tr></thead>
        <tbody>
        <?php foreach ($groups as $group => $rows): ?>
            <?php if (count($groups) > 1): ?>
                <tr class="group"><td colspan="4"><?= e($group) ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $e): ?>
                <tr>
                    <td class="box"><span></span></td>
                    <td class="qty"><?= e($e['qty_label']) ?></td>
                    <td>
                        <?= e($e['name']) ?>
                        <?php if ($e['note'] !== ''): ?><div class="sub"><?= e($e['note']) ?></div><?php endif; ?>
                        <?php if ($e['products']): ?><div class="sub"><?= e(implode(' · ', array_slice($e['products'], 0, 4))) ?></div><?php endif; ?>
                    </td>
                    <td class="price">
                        <?php if ($e['min'] !== null): ?>
                            <?= e($e['min'] === $e['max'] ? $fmt($e['min']) : $fmt($e['min']) . ' – ' . $fmt($e['max'])) ?><?= $e['price_unit'] ? ' / ' . e($e['price_unit']) : '' ?>
                            <?php if ($e['min_store']): ?><div class="sub">günstig: <?= e($e['min_store']) ?></div><?php endif; ?>
                        <?php else: ?>
                            <span class="sub">–</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
<script>
    if (new URLSearchParams(location.search).has('auto')) {
        window.addEventListener('load', function () { window.print(); });
    }
</script>
</body>
</html>
