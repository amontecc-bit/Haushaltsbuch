<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Repositories\InventoryRepository;
use App\Repositories\ShoppingRepository;
use App\Services\InventoryService;

/**
 * Vorrat je virtuellem Posten: Bestand, Chargen mit Ablaufdatum, Korrektur von Hand.
 * Eingaben und Anzeige in der Einheit der Referenz (z. B. Liter), gespeichert in Referenzeinheiten.
 */
final class InventoryController extends Controller
{
    public function index(): void
    {
        InventoryService::refreshAutoList($this->hid);
        $auto = (new ShoppingRepository())->autoListExists($this->hid) ? (new ShoppingRepository())->autoList($this->hid) : null;
        $actions = '<a class="btn btn-outline-primary" href="' . e(url('/recipes/suggestions')) . '"><i class="bi bi-lightbulb"></i> Rezeptvorschläge</a>'
            . ($auto ? '<a class="btn btn-outline-primary" href="' . e(url("/shopping/{$auto['id']}")) . '"><i class="bi bi-card-checklist"></i> Automatische Einkaufsliste</a>' : '')
            . '<a class="btn btn-outline-primary" href="' . e(url('/shopping/items')) . '"><i class="bi bi-collection"></i> Virtuelle Posten</a>';
        $this->view('inventory/index', [
            'title'   => 'Vorrat',
            'actions' => $actions,
            'items'   => $this->payload(),
            'scripts' => ['js/inventory.js'],
        ]);
    }

    /**
     * AJAX: Bestand ändern. mode=set (value in Einheit der Referenz), mode=delta (steps = ± Referenzmengen),
     * mode=empty (alles verbraucht).
     */
    public function adjust(int $itemId): void
    {
        $item = (new ShoppingRepository())->findItem($itemId, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $r = $this->request;
        $refQty = (float) $item['ref_quantity'] ?: 1.0;
        $stock = (new InventoryRepository())->stockMap($this->hid, date('Y-m-d'))[$itemId] ?? 0.0;
        $target = match ($r->str('mode')) {
            'set'   => (float) str_replace(',', '.', $r->str('value')) / $refQty,
            'delta' => $stock + (float) str_replace(',', '.', $r->str('steps', '0')),
            'empty' => 0.0,
            default => $this->json(['error' => 'Unbekannte Aktion'], 422),
        };
        InventoryService::adjust($this->hid, $item, $target);
        InventoryService::refreshAutoList($this->hid);
        $this->json(['ok' => true, 'items' => $this->payload()]);
    }

    /** AJAX: Restmenge (Einheit der Referenz) oder Ablaufdatum einer Charge ändern */
    public function updateLot(int $lotId): void
    {
        $repo = new InventoryRepository();
        $lot = $repo->findLot($lotId, $this->hid) ?? $this->json(['error' => 'Nicht gefunden'], 404);
        $item = (new ShoppingRepository())->findItem((int) $lot['shopping_item_id'], $this->hid);
        $data = [];
        if ($this->request->input('remaining') !== null) {
            $v = (float) str_replace(',', '.', $this->request->str('remaining'));
            $data['remaining'] = max(0, round($v / ((float) $item['ref_quantity'] ?: 1.0), 3));
            if ($data['remaining'] > (float) $lot['quantity']) {
                $data['quantity'] = $data['remaining'];
            }
        }
        if ($this->request->input('expires_on') !== null) {
            $data['expires_on'] = valid_date($this->request->str('expires_on'));
        }
        if ($data) {
            $repo->updateLot($lotId, $this->hid, $data);
            InventoryService::refreshAutoList($this->hid);
        }
        $this->json(['ok' => true, 'items' => $this->payload()]);
    }

    public function deleteLot(int $lotId): void
    {
        (new InventoryRepository())->deleteLot($lotId, $this->hid);
        InventoryService::refreshAutoList($this->hid);
        $this->json(['ok' => true, 'items' => $this->payload()]);
    }

    /** Vorrat aus den Einkäufen der letzten 90 Tage neu aufbauen (Korrekturen bleiben, Verbrauchtes bleibt verbraucht) */
    public function rebuild(): void
    {
        $n = InventoryService::rebuild($this->hid, 90);
        InventoryService::refreshAutoList($this->hid);
        $this->redirect('/inventory', "Vorrat aus $n Einkäufen der letzten 90 Tage abgeglichen.");
    }

    // ---------------------------------------------------------------------

    private function payload(): array
    {
        $repo = new InventoryRepository();
        $rows = $repo->stockByItem($this->hid, date('Y-m-d'));
        $tracked = array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => (int) $r['lot_count'] > 0));
        $lots = $repo->lots($this->hid, Auth::accountIds('view'), $tracked);
        $order = ['expired' => 0, 'out' => 1, 'low' => 2, 'ok' => 3, 'staple' => 4, 'none' => 5];
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $refQty = (float) $r['ref_quantity'];
            $unit = InventoryService::unit($r['ref_unit']);
            $status = InventoryService::status((float) $r['stock'], (float) $r['min_stock'], (int) $r['lot_count'] > 0, (bool) $r['staple'], $r['expired_on']);
            $out[] = [
                'id'          => $id,
                'name'        => $r['name'],
                'color'       => $r['category_color'] ?: '#adb5bd',
                'icon'        => $r['category_icon'] ?: 'basket',
                'status'      => $status,
                'sort'        => $order[$status],
                'stock'       => round((float) $r['stock'] * $refQty, 3),
                'stock_label' => InventoryService::label((float) $r['stock'], $refQty, $unit),
                'ref_label'   => InventoryService::label(1, $refQty, $unit),
                'ref_quantity' => $refQty,
                'unit'        => $unit ?? 'Stück',
                'min_label'   => (float) $r['min_stock'] > 0 ? InventoryService::label((float) $r['min_stock'], $refQty, $unit) : '',
                'shelf_days'  => $r['shelf_days'] !== null ? (int) $r['shelf_days'] : null,
                'auto'        => (bool) $r['auto_restock'] && !$r['staple'],
                'next_expiry' => $r['next_expiry'],
                'expired_on'  => $r['expired_on'],
                'lots'        => array_map(fn ($l) => [
                    'id'         => (int) $l['id'],
                    'source'     => $l['source'],
                    'label'      => $l['source'] === 'manual' ? 'Korrektur' : (($l['store'] ?: 'Einkauf') . ($l['product_name'] ? ' · ' . $l['product_name'] : '')),
                    'purchase_id' => $l['visible_purchase_id'] ? (int) $l['visible_purchase_id'] : null,
                    'acquired_on' => $l['acquired_on'],
                    'expires_on' => $l['expires_on'],
                    'expired'    => $l['expires_on'] !== null && $l['expires_on'] < date('Y-m-d'),
                    'quantity'   => InventoryService::label((float) $l['quantity'], $refQty, $unit),
                    'remaining'  => round((float) $l['remaining'] * $refQty, 3),
                    'remaining_label' => InventoryService::label((float) $l['remaining'], $refQty, $unit),
                ], $lots[$id] ?? []),
            ];
        }
        return $out;
    }
}
