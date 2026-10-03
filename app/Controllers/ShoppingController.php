<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\ProductRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ShoppingRepository;
use App\Services\CategorizationService;
use App\Services\ShoppingListService;

/**
 * Einkaufslisten aus virtuellen Posten. Ein virtueller Posten („Milch“) fasst mehrere echte Produkte aus den
 * Einkäufen zusammen („Weihenst. H-Milch 1,5%“, „ja! Fettarme Milch“); Preisspanne und Abgleich laufen darüber.
 */
final class ShoppingController extends Controller
{
    public function index(): void
    {
        $this->view('shopping/index', [
            'title'   => 'Einkaufslisten',
            'lists'   => (new ShoppingRepository())->lists($this->hid),
            'actions' => '<a class="btn btn-outline-primary" href="' . e(url('/shopping/items')) . '"><i class="bi bi-collection"></i> Virtuelle Posten</a>',
        ]);
    }

    public function store(): void
    {
        $name = mb_substr($this->request->str('name'), 0, 120) ?: 'Einkauf ' . date_de(date('Y-m-d'));
        $id = (new ShoppingRepository())->createList($this->hid, $name, Auth::id());
        $this->redirect("/shopping/$id");
    }

    public function show(int $id): void
    {
        $list = $this->list($id);
        Session::set('shopping_list', $id); // für „Zurück zur Liste“ aus den virtuellen Posten
        $ids = Auth::accountIds('view');
        $actions = '<a class="btn btn-outline-primary" href="' . e(url("/shopping/$id/print", ['auto' => 1])) . '" target="_blank"><i class="bi bi-printer"></i> Drucken / PDF</a>'
            . '<button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#matchModal"><i class="bi bi-check2-square"></i> Mit Einkauf abgleichen</button>'
            . '<a class="btn btn-outline-primary" href="' . e(url('/shopping/items')) . '"><i class="bi bi-collection"></i> Virtuelle Posten</a>';
        $this->view('shopping/show', [
            'title'     => $list['name'],
            'back'      => '/shopping',
            'actions'   => $actions,
            'list'      => $list,
            'entries'   => $this->entries($id),
            'items'     => $this->itemPicker(),
            'catalog'   => $this->catalog(),
            'purchases' => (new PurchaseRepository())->search($this->hid, $ids, ['from' => date('Y-m-d', strtotime('-30 days'))], 20),
            'scripts'   => ['js/shopping.js'],
        ]);
    }

    public function update(int $id): void
    {
        $this->list($id);
        $name = mb_substr($this->request->str('name'), 0, 120);
        if ($name !== '') {
            (new ShoppingRepository())->renameList($id, $this->hid, $name);
        }
        $this->redirect("/shopping/$id", 'Liste umbenannt.');
    }

    public function delete(int $id): void
    {
        $this->list($id);
        (new ShoppingRepository())->deleteList($id, $this->hid);
        $this->redirect('/shopping', 'Einkaufsliste gelöscht.');
    }

    /** Druckansicht (im Browser „Als PDF speichern“) */
    public function print(int $id): void
    {
        $list = $this->list($id);
        $entries = array_values(array_filter($this->entries($id), fn ($e) => !$e['done']));
        View::render('shopping/print', ['list' => $list, 'entries' => $entries], null);
    }

    /**
     * AJAX: Posten hinzufügen – vorhandener virtueller Posten (item_id), Produkt aus dem Fundus (product_id)
     * oder frei eingegebener Name (name). Für Produkte und freie Namen wird der passende virtuelle Posten gesucht
     * oder angelegt.
     */
    public function add(int $id): void
    {
        $this->list($id);
        $itemId = $this->resolveItem();
        if (!$itemId) {
            $this->json(['error' => 'Bitte einen Posten wählen oder eingeben.'], 422);
        }
        [$qty, $unit] = $this->quantity();
        $note = mb_substr($this->request->str('note'), 0, 190) ?: null;
        (new ShoppingRepository())->addEntry($id, $itemId, $qty, $unit, $note);
        $this->json(['ok' => true, 'entries' => $this->entries($id), 'items' => $this->itemPicker()]);
    }

    /** AJAX: Menge/Einheit/Notiz ändern oder abhaken (done) */
    public function updateEntry(int $id, int $entryId): void
    {
        $this->list($id);
        $repo = new ShoppingRepository();
        $repo->findEntry($entryId, $id) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $r = $this->request;
        if ($r->input('done') !== null) {
            if ($r->bool('done')) {
                $repo->markDone($entryId, null);
            } else {
                $repo->updateEntry($entryId, $id, ['done_at' => null, 'purchase_id' => null]);
            }
        }
        if ($r->input('quantity') !== null) {
            [$qty, $unit] = $this->quantity();
            $repo->updateEntry($entryId, $id, ['quantity' => $qty, 'unit' => $unit]);
        }
        if ($r->input('note') !== null) {
            $repo->updateEntry($entryId, $id, ['note' => mb_substr($r->str('note'), 0, 190) ?: null]);
        }
        $this->json(['ok' => true, 'entries' => $this->entries($id)]);
    }

    public function deleteEntry(int $id, int $entryId): void
    {
        $this->list($id);
        (new ShoppingRepository())->deleteEntry($entryId, $id);
        $this->json(['ok' => true, 'entries' => $this->entries($id)]);
    }

    public function clearDone(int $id): void
    {
        $this->list($id);
        (new ShoppingRepository())->clearDone($id);
        $this->json(['ok' => true, 'entries' => $this->entries($id)]);
    }

    /** AJAX: Liste mit einem gewählten Einkauf abgleichen – gekaufte Posten werden abgehakt */
    public function match(int $id): void
    {
        $this->list($id);
        $p = (new PurchaseRepository())->find((int) $this->request->int('purchase_id'), $this->hid, Auth::accountIds('view'))
            ?? $this->json(['error' => 'Einkauf nicht gefunden.'], 404);
        $n = ShoppingListService::matchPurchase($this->hid, (int) $p['id'], $p['purchase_date'], $id);
        $this->json([
            'ok' => true, 'matched' => $n, 'entries' => $this->entries($id), 'items' => $this->itemPicker(),
            'message' => $n ? "$n Posten als gekauft abgehakt." : 'Kein Posten der Liste in diesem Einkauf gefunden.',
        ]);
    }

    // ---- Virtuelle Posten -----------------------------------------------------

    public function items(): void
    {
        $list = $this->lastList();
        $this->view('shopping/items', [
            'title'      => 'Virtuelle Posten',
            'back'       => $list ? "/shopping/{$list['id']}" : '/shopping',
            'actions'    => $this->listButton($list)
                . '<a class="btn btn-outline-primary" href="' . e(url('/shopping/assign')) . '"><i class="bi bi-diagram-3"></i> Zuordnung</a>',
            'openId'     => (int) $this->request->int('open', 0),
            'items'      => $this->itemsPayload(),
            'catalog'    => $this->catalog(),
            'categories' => (new CategoryRepository())->all($this->hid, 'expense'),
            'scripts'    => ['js/shopping.js'],
        ]);
    }

    /** Gegenüberstellung virtuelle Posten ↔ echte Produkte, Zuordnung per Drag & Drop */
    public function assign(): void
    {
        $list = $this->lastList();
        $this->view('shopping/assign', [
            'title'   => 'Zuordnung der Produkte',
            'back'    => '/shopping/items',
            'actions' => $this->listButton($list)
                . '<a class="btn btn-outline-primary" href="' . e(url('/shopping/items')) . '"><i class="bi bi-collection"></i> Virtuelle Posten</a>',
            'items'   => $this->itemsPayload(),
            'catalog' => $this->catalog(),
            'scripts' => ['js/shopping.js'],
        ]);
    }

    /**
     * AJAX: Produkt verschieben. from_item_id (leer = aus „nicht zugeordnet“), to_item_id (leer = Zuordnung lösen,
     * „new“ = neuen virtuellen Posten mit dem Namen des Produkts anlegen).
     */
    public function move(): void
    {
        $r = $this->request;
        $repo = new ShoppingRepository();
        $product = (new ProductRepository())->find((int) $r->int('product_id'), $this->hid)
            ?? $this->json(['error' => 'Produkt nicht gefunden.'], 404);
        $pid = (int) $product['id'];
        $from = $r->int('from_item_id') ? $repo->findItem((int) $r->int('from_item_id'), $this->hid) : null;
        $to = null;
        if ($r->str('to_item_id') === 'new') {
            $to = $repo->findItem($this->itemByName($product['name'], $product['default_category_id'] ? (int) $product['default_category_id'] : null), $this->hid);
        } elseif ($r->int('to_item_id')) {
            $to = $repo->findItem((int) $r->int('to_item_id'), $this->hid) ?? $this->json(['error' => 'Virtueller Posten nicht gefunden.'], 404);
        }
        $repo->transaction(function () use ($repo, $pid, $from, $to) {
            if ($from && (!$to || (int) $from['id'] !== (int) $to['id'])) {
                $repo->unlinkProduct((int) $from['id'], $pid);
            }
            if ($to) {
                $repo->linkProduct((int) $to['id'], $pid);
            }
        });
        $this->json(['ok' => true, 'items' => $this->itemsPayload(), 'catalog' => $this->catalog()]);
    }

    public function storeItem(): void
    {
        $name = mb_substr($this->request->str('name'), 0, 190);
        if ($name === '') {
            $this->json(['error' => 'Bitte einen Namen eingeben.'], 422);
        }
        $this->itemByName($name);
        $this->json(['ok' => true, 'items' => $this->itemsPayload()]);
    }

    public function updateItem(int $itemId): void
    {
        $repo = new ShoppingRepository();
        $repo->findItem($itemId, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $data = [];
        $name = mb_substr($this->request->str('name'), 0, 190);
        if ($name !== '') {
            $norm = self::normalize($name);
            $other = $repo->findItemByNormalized($this->hid, $norm);
            if ($other && (int) $other['id'] !== $itemId) {
                $this->json(['error' => "Den Posten „{$other['name']}“ gibt es schon."], 422);
            }
            $data += ['name' => $name, 'normalized' => mb_substr($norm, 0, 190)];
        }
        if ($this->request->input('category_id') !== null) {
            $data['category_id'] = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        }
        if ($data) {
            $repo->updateItem($itemId, $this->hid, $data);
        }
        $this->json(['ok' => true, 'items' => $this->itemsPayload()]);
    }

    public function deleteItem(int $itemId): void
    {
        (new ShoppingRepository())->deleteItem($itemId, $this->hid);
        $this->json(['ok' => true, 'items' => $this->itemsPayload()]);
    }

    public function linkProduct(int $itemId): void
    {
        $repo = new ShoppingRepository();
        $repo->findItem($itemId, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $product = (new ProductRepository())->find((int) $this->request->int('product_id'), $this->hid)
            ?? $this->json(['error' => 'Produkt nicht gefunden.'], 404);
        $repo->linkProduct($itemId, (int) $product['id']);
        $this->json(['ok' => true, 'items' => $this->itemsPayload()]);
    }

    public function unlinkProduct(int $itemId, int $productId): void
    {
        $repo = new ShoppingRepository();
        $repo->findItem($itemId, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $repo->unlinkProduct($itemId, $productId);
        $this->json(['ok' => true, 'items' => $this->itemsPayload()]);
    }

    // ---------------------------------------------------------------------

    /** Zuletzt geöffnete Einkaufsliste (für den Rückweg aus den virtuellen Posten) */
    private function lastList(): ?array
    {
        $id = (int) Session::get('shopping_list');
        return $id ? (new ShoppingRepository())->findList($id, $this->hid) : null;
    }

    private function listButton(?array $list): string
    {
        return $list
            ? '<a class="btn btn-primary" href="' . e(url("/shopping/{$list['id']}")) . '"><i class="bi bi-card-checklist"></i> Zurück zu „' . e($list['name']) . '“</a>'
            : '<a class="btn btn-primary" href="' . e(url('/shopping')) . '"><i class="bi bi-card-checklist"></i> Einkaufslisten</a>';
    }

    private function list(int $id): array
    {
        $list = (new ShoppingRepository())->findList($id, $this->hid);
        if (!$list) {
            if ($this->request->wantsJson()) {
                $this->json(['error' => 'Liste nicht gefunden.'], 404);
            }
            $this->notFound();
        }
        return $list;
    }

    /** Einträge einer Liste für Anzeige und Druck, inkl. Preisspanne und zugeordneter Produkte */
    private function entries(int $listId): array
    {
        $repo = new ShoppingRepository();
        $accountIds = Auth::accountIds('view');
        $rows = $repo->entries($listId, $accountIds);
        $itemIds = array_values(array_unique(array_map(fn ($e) => (int) $e['shopping_item_id'], $rows)));
        $stats = $repo->priceStats($this->hid, $accountIds, $itemIds);
        $products = $itemIds ? $repo->productsByItem($this->hid, $itemIds) : [];
        return array_map(function ($e) use ($stats, $products) {
            $itemId = (int) $e['shopping_item_id'];
            $s = $stats[$itemId] ?? null;
            return [
                'id'        => (int) $e['id'],
                'item_id'   => $itemId,
                'name'      => $e['name'],
                'quantity'  => (float) $e['quantity'],
                'unit'      => (string) $e['unit'],
                'qty_label' => ShoppingListService::quantityLabel($e['quantity'], $e['unit']),
                'note'      => (string) $e['note'],
                'done'      => $e['done_at'] !== null,
                'purchase'  => $e['purchase_date'] !== null
                    ? ['id' => (int) $e['purchase_id'], 'label' => ($e['purchase_store'] ?: 'Einkauf') . ' · ' . date_de($e['purchase_date'])]
                    : null,
                'group'     => $e['cat_group'] ?? 'Ohne Kategorie',
                'color'     => $e['category_color'] ?: '#adb5bd',
                'icon'      => $e['category_icon'] ?: 'basket',
                'products'  => array_column($products[$itemId] ?? [], 'name'),
                'min'       => $s ? (float) $s['min'] : null,
                'max'       => $s ? (float) $s['max'] : null,
                'price_unit' => $s['unit'] ?? null,
                'min_store' => $s['min_store'] ?? null,
            ];
        }, $rows);
    }

    /** Virtuelle Posten für die Suche beim Hinzufügen */
    private function itemPicker(): array
    {
        $repo = new ShoppingRepository();
        $products = $repo->productsByItem($this->hid);
        return array_map(fn ($i) => [
            'id' => (int) $i['id'], 'name' => $i['name'], 'products' => array_column($products[(int) $i['id']] ?? [], 'name'),
        ], $repo->items($this->hid));
    }

    /** Produkte aus den Einkäufen mit Preisspanne und (erstem) virtuellem Posten */
    private function catalog(): array
    {
        $repo = new ShoppingRepository();
        $itemOf = [];
        foreach ($repo->productsByItem($this->hid) as $itemId => $prods) {
            foreach ($prods as $p) {
                $itemOf[$p['id']] ??= $itemId;
            }
        }
        return array_map(fn ($p) => [
            'id' => (int) $p['id'], 'name' => $p['name'], 'cnt' => (int) $p['cnt'],
            'min' => (float) $p['min_price'], 'max' => (float) $p['max_price'], 'unit' => $p['unit'],
            'item_id' => $itemOf[(int) $p['id']] ?? null,
        ], $repo->productCatalog($this->hid, Auth::accountIds('view')));
    }

    private function itemsPayload(): array
    {
        $repo = new ShoppingRepository();
        $items = $repo->items($this->hid);
        $ids = array_map(fn ($i) => (int) $i['id'], $items);
        $products = $repo->productsByItem($this->hid);
        $stats = $repo->priceStats($this->hid, Auth::accountIds('view'), $ids);
        return array_map(fn ($i) => [
            'id' => (int) $i['id'], 'name' => $i['name'], 'category_id' => $i['category_id'] ? (int) $i['category_id'] : '',
            'color' => $i['category_color'] ?: '#adb5bd', 'icon' => $i['category_icon'] ?: 'basket',
            'products' => $products[(int) $i['id']] ?? [],
            'min' => isset($stats[(int) $i['id']]) ? (float) $stats[(int) $i['id']]['min'] : null,
            'max' => isset($stats[(int) $i['id']]) ? (float) $stats[(int) $i['id']]['max'] : null,
            'price_unit' => $stats[(int) $i['id']]['unit'] ?? null,
        ], $items);
    }

    /** Virtuellen Posten aus item_id, product_id oder name ermitteln bzw. anlegen */
    private function resolveItem(): ?int
    {
        $r = $this->request;
        $repo = new ShoppingRepository();
        if ($r->int('item_id')) {
            $item = $repo->findItem((int) $r->int('item_id'), $this->hid);
            return $item ? (int) $item['id'] : null;
        }
        if ($r->int('product_id')) {
            $product = (new ProductRepository())->find((int) $r->int('product_id'), $this->hid);
            if (!$product) {
                return null;
            }
            $item = $repo->itemForProduct($this->hid, (int) $product['id']);
            if ($item) {
                return (int) $item['id'];
            }
            $itemId = $this->itemByName($product['name'], $product['default_category_id'] ? (int) $product['default_category_id'] : null);
            $repo->linkProduct($itemId, (int) $product['id']);
            return $itemId;
        }
        $name = mb_substr($r->str('name'), 0, 190);
        return $name !== '' ? $this->itemByName($name) : null;
    }

    /**
     * Virtuellen Posten mit gleichem normalisierten Namen holen oder anlegen. Neue Posten bekommen eine
     * Kategorie (Vorschlag) und das gleichnamige Produkt aus den Einkäufen, falls vorhanden.
     */
    private function itemByName(string $name, ?int $categoryId = null): int
    {
        $repo = new ShoppingRepository();
        $norm = self::normalize($name);
        $item = $repo->findItemByNormalized($this->hid, mb_substr($norm, 0, 190));
        if ($item) {
            return (int) $item['id'];
        }
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1); // „bananen“ → „Bananen“
        $categoryId ??= (new CategorizationService($this->hid))->suggestForItem($name)['category_id'];
        $id = $repo->createItem($this->hid, $name, $norm, $categoryId);
        $product = (new ProductRepository())->indexByNormalized($this->hid)[CategorizationService::normalizeProduct($name)] ?? null;
        if ($product) {
            $repo->linkProduct($id, (int) $product['id']);
        }
        return $id;
    }

    /** Vergleichsschlüssel für virtuelle Posten (wie bei Produkten, notfalls nur kleingeschrieben) */
    private static function normalize(string $name): string
    {
        return CategorizationService::normalizeProduct($name) ?: mb_strtolower(trim($name));
    }

    /** @return array{0:float, 1:?string} Menge, Einheit */
    private function quantity(): array
    {
        $qty = (float) str_replace(',', '.', $this->request->str('quantity', '1'));
        $qty = $qty > 0 ? min(round($qty, 3), 9999) : 1.0;
        $unit = $this->request->str('unit');
        return [$qty, array_key_exists($unit, ShoppingListService::UNITS) && $unit !== '' ? $unit : null];
    }
}
