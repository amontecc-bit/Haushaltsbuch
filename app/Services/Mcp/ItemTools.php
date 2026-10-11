<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Core\Auth;
use App\Repositories\ProductRepository;
use App\Repositories\ShoppingRepository;
use App\Services\InventoryService;
use App\Services\ShoppingListService;

/**
 * Pflege der virtuellen Posten und ihrer Zuordnung zu echten Produkten – damit Claude beim Aufräumen helfen kann
 * (Dubletten zusammenführen, Produkte zuordnen, Referenzmengen und Verbrauchsdauer setzen).
 * Echte Produkte nur mit Name, Anzahl Käufe und letztem Kaufdatum – keine Preise, keine Geschäfte.
 */
final class ItemTools extends ToolSet
{
    private const REF_UNITS = ['Stück', 'g', 'kg', 'ml', 'l'];

    public function tools(): array
    {
        $item = ['type' => 'string', 'description' => 'Name des virtuellen Postens, z. B. „Milch“'];
        $itemId = ['type' => 'integer', 'description' => 'alternativ die item_id aus list_items'];
        $settings = [
            'category'     => ['type' => 'string', 'description' => 'Ausgaben-Kategorie, z. B. „Lebensmittel“ oder „Lebensmittel › Milchprodukte“'],
            'ref_quantity' => ['type' => 'number', 'exclusiveMinimum' => 0, 'description' => 'Referenzmenge, in der Vorrat und Mindestbestand gezählt werden, z. B. 1 (l) oder 6 (Stück)'],
            'ref_unit'     => ['type' => 'string', 'enum' => self::REF_UNITS],
            'shelf_days'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650, 'description' => 'Verbrauchsdauer in Tagen ab Kauf; 0 = läuft nicht ab'],
            'min_stock'    => ['type' => 'number', 'minimum' => 0, 'description' => 'Mindestbestand in Vielfachen der Referenzmenge; 0 = erst nachkaufen, wenn leer'],
            'staple'       => ['type' => 'boolean', 'description' => 'Grundvorrat (Salz, Öl …): gilt in Rezepten immer als vorhanden, wird nicht gezählt'],
            'auto_restock' => ['type' => 'boolean', 'description' => 'bei Bedarf automatisch auf die Liste „Automatisch“'],
        ];

        return [
            'list_items' => [
                'title' => 'Virtuelle Posten anzeigen',
                'description' => 'Virtuelle Posten (z. B. „Milch“) mit Kategorie, Referenzmenge, Verbrauchsdauer, Mindestbestand, '
                    . 'zugeordneten echten Produkten (mit Faktor = Anteil der Referenzmenge je Packung) und Rezepten, die sie verwenden. '
                    . 'Zum Aufräumen: Dubletten, falsche Zuordnungen, fehlende Referenzen finden.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'search' => ['type' => 'string', 'description' => 'Teil des Posten- oder Produktnamens'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->listItems($a),
            ],
            'list_products' => [
                'title' => 'Echte Produkte anzeigen',
                'description' => 'Echte Produkte aus den Einkäufen (Namen wie auf dem Kassenbon) mit Anzahl Käufen, letztem Kaufdatum '
                    . 'und den virtuellen Posten, denen sie zugeordnet sind. Ohne Preise und Geschäfte. unassigned_only=true zeigt '
                    . 'Produkte, die noch keinem Posten zugeordnet sind.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'search'          => ['type' => 'string'],
                    'unassigned_only' => ['type' => 'boolean'],
                    'limit'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'description' => 'Standard 100'],
                ]],
                'annotations' => self::readOnly(),
                'handler' => fn (array $a) => $this->listProducts($a),
            ],
            'create_item' => [
                'title' => 'Virtuellen Posten anlegen',
                'description' => 'Neuen virtuellen Posten anlegen (allgemeiner Name wie „Haferflocken“). Ohne category wird eine '
                    . 'vorgeschlagen; ein gleichnamiges Produkt wird automatisch zugeordnet.',
                'inputSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']] + $settings, 'required' => ['name']],
                'annotations' => self::write(false, false),
                'handler' => fn (array $a) => $this->createItem($a),
            ],
            'update_item' => [
                'title' => 'Virtuellen Posten ändern',
                'description' => 'Einstellungen eines virtuellen Postens ändern (nur übergebene Felder). new_name benennt um. '
                    . 'Eine geänderte Referenz oder Verbrauchsdauer berechnet den Vorrat aus den Einkäufen der letzten 90 Tage neu; '
                    . 'Faktoren der zugeordneten Produkte beziehen sich auf die Referenzmenge und müssen ggf. angepasst werden.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'item' => $item, 'item_id' => $itemId, 'new_name' => ['type' => 'string'],
                ] + $settings],
                'annotations' => self::write(false, true),
                'handler' => fn (array $a) => $this->updateItem($a),
            ],
            'assign_products' => [
                'title' => 'Produkte zuordnen',
                'description' => 'Echte Produkte (product_ids aus list_products) einem virtuellen Posten zuordnen. Standardmäßig werden '
                    . 'sie dabei von anderen Posten entfernt (verschoben). factor = Anteil der Referenzmenge je gekaufter Einheit '
                    . '(Referenz 1 l, 0,5-l-Flasche → 0.5); ohne Angabe aus dem Produktnamen geschätzt. Auch zum Ändern von '
                    . 'factor/shelf_days bereits zugeordneter Produkte. Der Vorrat wird neu berechnet.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'item' => $item, 'item_id' => $itemId,
                    'product_ids'  => ['type' => 'array', 'minItems' => 1, 'maxItems' => 100, 'items' => ['type' => 'integer']],
                    'factor'       => ['type' => 'number', 'exclusiveMinimum' => 0],
                    'shelf_days'   => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650, 'description' => 'eigene Verbrauchsdauer der Produkte; 0 = wie der Posten'],
                    'keep_other_assignments' => ['type' => 'boolean', 'description' => 'true = zusätzlich zuordnen statt verschieben'],
                ], 'required' => ['product_ids']],
                'annotations' => self::write(false, true),
                'handler' => fn (array $a) => $this->assignProducts($a),
            ],
            'unassign_products' => [
                'title' => 'Zuordnung lösen',
                'description' => 'Zuordnung echter Produkte zu einem virtuellen Posten lösen (z. B. falsch zugeordnet). Der Vorrat wird neu berechnet.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'item' => $item, 'item_id' => $itemId,
                    'product_ids' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer']],
                ], 'required' => ['product_ids']],
                'annotations' => self::write(true, true),
                'handler' => fn (array $a) => $this->unassignProducts($a),
            ],
            'merge_items' => [
                'title' => 'Posten zusammenführen',
                'description' => 'Doppelte virtuelle Posten zusammenführen: source geht in target auf (Produkte, Einträge auf '
                    . 'Einkaufslisten, Rezeptzutaten und Vorrat ziehen um, Mengen werden umgerechnet) und wird gelöscht. '
                    . 'Referenzen müssen dieselbe Größe haben (Gewicht, Volumen oder Stück). Vorher mit dem Benutzer abstimmen.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'source' => ['type' => 'string', 'description' => 'Posten, der wegfällt'], 'source_id' => ['type' => 'integer'],
                    'target' => ['type' => 'string', 'description' => 'Posten, der bleibt'], 'target_id' => ['type' => 'integer'],
                ]],
                'annotations' => self::write(true, false),
                'handler' => fn (array $a) => $this->mergeItems($a),
            ],
            'delete_item' => [
                'title' => 'Posten löschen',
                'description' => 'Virtuellen Posten löschen (samt Einträgen auf Einkaufslisten und Vorrat; die echten Produkte und '
                    . 'Einkäufe bleiben). Nicht möglich, solange ein Rezept ihn verwendet – dann merge_items nutzen. Vorher bestätigen lassen.',
                'inputSchema' => ['type' => 'object', 'properties' => ['item' => $item, 'item_id' => $itemId]],
                'annotations' => self::write(true, true),
                'handler' => fn (array $a) => $this->deleteItem($a),
            ],
        ];
    }

    // ---------------------------------------------------------------------

    private function listItems(array $a): array
    {
        $repo = new ShoppingRepository();
        $products = $repo->productsByItem($this->hid);
        $usage = $repo->recipeUsage($this->hid);
        $search = self::str($a, 'search', 100);
        $out = [];
        foreach ($repo->items($this->hid) as $i) {
            $id = (int) $i['id'];
            $prods = $products[$id] ?? [];
            if ($search !== '' && mb_stripos($i['name'], $search) === false
                && !array_filter($prods, fn ($p) => mb_stripos($p['name'], $search) !== false)) {
                continue;
            }
            $out[] = $this->itemPayload($i, $prods, $usage[$id] ?? []);
        }
        return ['count' => count($out), 'items' => $out];
    }

    private function listProducts(array $a): array
    {
        $assigned = [];
        $repo = new ShoppingRepository();
        $names = array_column($repo->items($this->hid), 'name', 'id');
        foreach ($repo->productsByItem($this->hid) as $itemId => $prods) {
            foreach ($prods as $p) {
                $assigned[$p['id']][] = $names[$itemId] ?? '?';
            }
        }
        $search = self::str($a, 'search', 100);
        $unassignedOnly = self::bool($a, 'unassigned_only', false);
        $limit = max(1, min(500, self::int($a, 'limit') ?? 100));
        $out = [];
        $total = 0;
        foreach ((new ProductRepository())->overview($this->hid, Auth::accountIds('view')) as $p) {
            $id = (int) $p['id'];
            if (($unassignedOnly && isset($assigned[$id])) || ($search !== '' && mb_stripos($p['name'], $search) === false)) {
                continue;
            }
            $total++;
            if (count($out) < $limit) {
                $out[] = array_filter([
                    'product_id' => $id, 'name' => $p['name'], 'bought' => (int) $p['bought'], 'last_bought' => $p['last_bought'],
                    'items' => $assigned[$id] ?? [],
                ], fn ($v) => $v !== null);
            }
        }
        return ['total' => $total, 'shown' => count($out), 'products' => $out];
    }

    private function createItem(array $a): array
    {
        $repo = new ShoppingRepository();
        $name = self::str($a, 'name');
        if ($name === '') {
            throw new ToolError('Bitte einen Namen angeben.');
        }
        $existing = $repo->findItemByNormalized($this->hid, mb_substr(ShoppingListService::normalize($name), 0, 190));
        if ($existing) {
            throw new ToolError("Den Posten „{$existing['name']}“ gibt es schon (item_id {$existing['id']}) – update_item zum Ändern.");
        }
        $data = $this->settings($a);
        $id = ShoppingListService::itemByName($this->hid, $name, $data['category_id'] ?? null);
        unset($data['category_id']);
        if ($data) {
            $repo->updateItem($id, $this->hid, $data);
        }
        $item = $repo->findItem($id, $this->hid);
        $this->log('create_item', "Posten „{$item['name']}“ angelegt");
        return $this->payloadById($id);
    }

    private function updateItem(array $a): array
    {
        $repo = new ShoppingRepository();
        $item = $this->findItem($a);
        $id = (int) $item['id'];
        $data = $this->settings($a);
        $newName = self::str($a, 'new_name');
        if ($newName !== '' && $newName !== $item['name']) {
            $norm = ShoppingListService::normalize($newName);
            $other = $repo->findItemByNormalized($this->hid, mb_substr($norm, 0, 190));
            if ($other && (int) $other['id'] !== $id) {
                throw new ToolError("Den Posten „{$other['name']}“ gibt es schon (item_id {$other['id']}) – ggf. merge_items nutzen.");
            }
            $data += ['name' => $newName, 'normalized' => mb_substr($norm, 0, 190)];
        }
        if (!$data) {
            throw new ToolError('Keine Änderung übergeben.');
        }
        $repo->updateItem($id, $this->hid, $data);
        if (array_intersect(array_keys($data), ['ref_quantity', 'ref_unit', 'shelf_days'])) {
            InventoryService::rebuild($this->hid); // Mengen/Ablauf der Chargen neu berechnen (wie in der Web-Oberfläche)
        }
        InventoryService::refreshAutoList($this->hid);
        $this->log('update_item', "Posten „{$item['name']}“ geändert: " . implode(', ', array_keys(array_diff_key($data, ['normalized' => 1]))));
        return $this->payloadById($id);
    }

    private function assignProducts(array $a): array
    {
        $repo = new ShoppingRepository();
        $products = new ProductRepository();
        $item = $this->findItem($a);
        $id = (int) $item['id'];
        $factor = self::num($a, 'factor');
        if ($factor !== null && ($factor <= 0 || $factor > 99999)) {
            throw new ToolError('factor muss größer als 0 sein.');
        }
        $shelf = array_key_exists('shelf_days', $a) ? self::days($a, 'shelf_days') : false;
        $keep = self::bool($a, 'keep_other_assignments', false);
        $current = array_column($repo->productsByItem($this->hid, [$id])[$id] ?? [], null, 'id');
        $done = [];
        $missing = [];
        foreach (self::ints($a, 'product_ids') as $pid) {
            $p = $products->find($pid, $this->hid);
            if (!$p) {
                $missing[] = $pid;
                continue;
            }
            $repo->transaction(function () use ($repo, $id, $pid, $p, $item, $factor, $shelf, $keep, $current) {
                if (!$keep) {
                    foreach ($repo->itemIdsForProduct($this->hid, $pid) as $other) {
                        if ($other !== $id) {
                            $repo->unlinkProduct($other, $pid);
                        }
                    }
                }
                $f = $factor ?? $current[$pid]['factor'] ?? InventoryService::guessFactor($p['name'], (float) $item['ref_quantity'], $item['ref_unit']) ?? 1.0;
                $repo->linkProduct($id, $pid, $f);
                $repo->updateLink($id, $pid, round($f, 4), $shelf === false ? ($current[$pid]['shelf_days'] ?? null) : $shelf);
            });
            InventoryService::rebuild($this->hid, 90, $pid);
            $done[] = $p['name'];
        }
        if (!$done) {
            throw new ToolError('Keines der Produkte gefunden – list_products zeigt die product_id.');
        }
        InventoryService::refreshAutoList($this->hid);
        $this->log('assign_products', "Zu „{$item['name']}“ zugeordnet: " . implode(', ', $done));
        return ['item' => $this->payloadById($id), 'assigned' => $done, 'not_found' => $missing];
    }

    private function unassignProducts(array $a): array
    {
        $repo = new ShoppingRepository();
        $item = $this->findItem($a);
        $id = (int) $item['id'];
        $current = array_column($repo->productsByItem($this->hid, [$id])[$id] ?? [], null, 'id');
        $done = [];
        foreach (self::ints($a, 'product_ids') as $pid) {
            if (isset($current[$pid])) {
                $repo->unlinkProduct($id, $pid);
                InventoryService::rebuild($this->hid, 90, $pid);
                $done[] = $current[$pid]['name'];
            }
        }
        if (!$done) {
            throw new ToolError("Keines der Produkte ist „{$item['name']}“ zugeordnet.");
        }
        InventoryService::refreshAutoList($this->hid);
        $this->log('unassign_products', "Von „{$item['name']}“ gelöst: " . implode(', ', $done));
        return ['item' => $this->payloadById($id), 'unassigned' => $done];
    }

    private function mergeItems(array $a): array
    {
        $source = $this->findItem($a, nameKey: 'source', idKey: 'source_id');
        $target = $this->findItem($a, nameKey: 'target', idKey: 'target_id');
        try {
            ShoppingListService::mergeItems($this->hid, $source, $target);
        } catch (\DomainException $e) {
            throw new ToolError($e->getMessage());
        }
        $this->log('merge_items', "„{$source['name']}“ in „{$target['name']}“ zusammengeführt");
        return ['merged' => $source['name'], 'into' => $this->payloadById((int) $target['id'])];
    }

    private function deleteItem(array $a): string
    {
        $repo = new ShoppingRepository();
        $item = $this->findItem($a);
        $recipes = $repo->recipeUsage($this->hid)[(int) $item['id']] ?? [];
        if ($recipes) {
            throw new ToolError("„{$item['name']}“ wird in Rezepten verwendet (" . implode(', ', $recipes)
                . ') – stattdessen mit merge_items in einen anderen Posten überführen.');
        }
        $repo->deleteItem((int) $item['id'], $this->hid);
        InventoryService::refreshAutoList($this->hid);
        $this->log('delete_item', "Posten „{$item['name']}“ gelöscht");
        return "Posten „{$item['name']}“ gelöscht.";
    }

    // ---------------------------------------------------------------------

    /** Einstellungen aus den Parametern (nur übergebene Felder), Spalten wie in shopping_items */
    private function settings(array $a): array
    {
        $data = [];
        if (self::str($a, 'category') !== '') {
            $data['category_id'] = $this->categoryByName(self::str($a, 'category'));
        }
        if (array_key_exists('ref_quantity', $a)) {
            $q = self::num($a, 'ref_quantity');
            if ($q === null || $q <= 0) {
                throw new ToolError('ref_quantity muss größer als 0 sein.');
            }
            $data['ref_quantity'] = min(round($q, 3), 99999);
        }
        if (self::str($a, 'ref_unit', 20) !== '') {
            $data['ref_unit'] = self::stockUnit(self::str($a, 'ref_unit', 20));
        }
        if (array_key_exists('shelf_days', $a)) {
            $data['shelf_days'] = self::days($a, 'shelf_days');
        }
        if (array_key_exists('min_stock', $a)) {
            $m = self::num($a, 'min_stock');
            if ($m === null || $m < 0) {
                throw new ToolError('min_stock muss 0 oder größer sein.');
            }
            $data['min_stock'] = min(round($m, 3), 9999);
        }
        foreach (['staple', 'auto_restock'] as $k) {
            if (array_key_exists($k, $a)) {
                $data[$k] = self::bool($a, $k, false) ? 1 : 0;
            }
        }
        return $data;
    }

    /** Verbrauchsdauer in Tagen; 0/leer = läuft nicht ab */
    private static function days(array $a, string $key): ?int
    {
        $d = self::int($a, $key);
        return $d !== null && $d > 0 ? min($d, 3650) : null;
    }

    private function payloadById(int $id): array
    {
        $repo = new ShoppingRepository();
        foreach ($repo->items($this->hid) as $i) {
            if ((int) $i['id'] === $id) {
                return $this->itemPayload($i, $repo->productsByItem($this->hid, [$id])[$id] ?? [], $repo->recipeUsage($this->hid)[$id] ?? []);
            }
        }
        throw new ToolError("Posten $id gibt es nicht.");
    }

    private function itemPayload(array $i, array $products, array $recipes): array
    {
        $refQty = (float) $i['ref_quantity'];
        return array_filter([
            'item_id'      => (int) $i['id'],
            'name'         => $i['name'],
            'category'     => $i['category_name'] ?? null,
            'reference'    => InventoryService::label(1, $refQty, $i['ref_unit']),
            'shelf_days'   => $i['shelf_days'] !== null ? (int) $i['shelf_days'] : null,
            'min_stock'    => (float) $i['min_stock'] > 0 ? (float) $i['min_stock'] : null,
            'staple'       => (bool) $i['staple'],
            'auto_restock' => (bool) $i['auto_restock'],
            'products'     => array_map(fn ($p) => array_filter([
                'product_id' => $p['id'], 'name' => $p['name'], 'factor' => $p['factor'], 'shelf_days' => $p['shelf_days'],
            ], fn ($v) => $v !== null), $products),
            'recipes'      => $recipes ?: null,
        ], fn ($v) => $v !== null);
    }
}
