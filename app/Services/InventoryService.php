<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\PurchaseRepository;
use App\Repositories\ShoppingRepository;

/**
 * Vorrat je virtuellem Posten.
 *
 * Mengen werden in Referenzeinheiten geführt: 1 = einmal die Referenzmenge des Postens (z. B. 1 l Milch). Ein
 * gekauftes Produkt trägt mit seinem Ausgleichsfaktor bei (0,5-l-Packung = 0,5). Jeder Kauf wird zu einer Charge mit
 * Ablaufdatum (Kaufdatum + Verbrauchsdauer des Produkts, sonst des Postens); abgelaufene Chargen gelten als verbraucht.
 * Kochen und Korrekturen ziehen von den zuerst ablaufenden Chargen ab.
 */
final class InventoryService
{
    /** Einheiten mit Umrechnung auf eine Grundeinheit (g bzw. ml); alles andere zählt als Stück */
    private const UNIT_BASE = ['g' => ['m', 1], 'kg' => ['m', 1000], 'ml' => ['v', 1], 'l' => ['v', 1000]];

    /** Einheiten für Referenzmengen und Rezeptzutaten ('' = Stück) */
    public const UNITS = ['' => 'Stück', 'g' => 'g', 'kg' => 'kg', 'ml' => 'ml', 'l' => 'l'];

    private const EPS = 0.0005;

    // ---- Reine Funktionen (testbar) ------------------------------------------------

    /** Einheit normalisieren: „KG“ → „kg“, „Ltr“ → „l“, unbekannt/Stück → null */
    public static function unit(?string $unit): ?string
    {
        $u = mb_strtolower(trim((string) $unit));
        $u = match ($u) {
            'gr', 'gramm' => 'g',
            'ltr', 'liter' => 'l',
            default => $u,
        };
        return isset(self::UNIT_BASE[$u]) ? $u : null;
    }

    /** Sind Menge und Referenz in derselben Größe (Gewicht, Volumen, Stück)? */
    public static function compatible(?string $unit, ?string $refUnit): bool
    {
        $a = self::unit($unit);
        $b = self::unit($refUnit);
        return ($a === null && $b === null) || ($a !== null && $b !== null && self::UNIT_BASE[$a][0] === self::UNIT_BASE[$b][0]);
    }

    /**
     * Menge in Referenzeinheiten umrechnen: 500 g bei Referenz 1 kg = 0,5; 3 Stück bei Referenz 6 Stück = 0,5.
     * null, wenn die Einheiten nicht zueinander passen.
     */
    public static function toRef(float $qty, ?string $unit, float $refQty, ?string $refUnit): ?float
    {
        if (!self::compatible($unit, $refUnit) || $refQty <= 0) {
            return null;
        }
        $u = self::unit($unit);
        $r = self::unit($refUnit);
        $mul = $u === null ? 1 : self::UNIT_BASE[$u][1] / self::UNIT_BASE[$r][1];
        return $qty * $mul / $refQty;
    }

    /** Umkehrung von toRef(): Referenzeinheiten in einer Einheit ausdrücken (null bei unpassender Einheit) */
    public static function fromRef(float $ref, ?string $unit, float $refQty, ?string $refUnit): ?float
    {
        $one = self::toRef(1, $unit, $refQty, $refUnit);
        return $one ? $ref / $one : null;
    }

    /**
     * Beitrag eines Einkaufspostens zum Vorrat in Referenzeinheiten. Stückware: Anzahl × Faktor; lose Ware mit
     * Gewicht/Volumen (1,2 kg Bananen) wird umgerechnet, wenn die Referenz dazu passt, sonst ebenfalls × Faktor.
     */
    public static function purchaseAmount(float $qty, ?string $unit, float $factor, float $refQty, ?string $refUnit): float
    {
        if (self::unit($unit) !== null) {
            $ref = self::toRef($qty, $unit, $refQty, $refUnit);
            if ($ref !== null) {
                return round($ref, 3);
            }
        }
        return round($qty * $factor, 3);
    }

    /**
     * Ausgleichsfaktor aus dem Produktnamen schätzen: „Milch 0,5l“ bei Referenz 1 l = 0,5, „Mehl 2,5 kg“ bei 1 kg = 2,5,
     * „Joghurt 6x150g“ bei 1 kg = 0,9, „Eier 10er“ bei 1 Stück = 10. null, wenn nichts Passendes im Namen steht.
     */
    public static function guessFactor(string $productName, float $refQty, ?string $refUnit): ?float
    {
        $name = mb_strtolower($productName);
        if (preg_match('/(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*(kg|gr|g|ml|cl|ltr|l)\b/u', $name, $m)) {
            $qty = (float) str_replace(',', '.', $m[2]) * ($m[1] !== '' ? (int) $m[1] : 1);
            $unit = $m[3];
            if ($unit === 'cl') {
                [$qty, $unit] = [$qty * 10, 'ml'];
            }
            $f = self::toRef($qty, $unit, $refQty, $refUnit);
            return $f !== null && $f > 0 ? round($f, 4) : null;
        }
        if (self::unit($refUnit) === null && preg_match('/(\d+)\s*(?:er\b|st\b|stk\b|stück)/u', $name, $m)) {
            return round((int) $m[1] / $refQty, 4) ?: null;
        }
        return null;
    }

    /**
     * Menge von Chargen abziehen (Chargen in Verbrauchsreihenfolge).
     * @param array<int, array{id:int|string, remaining:float|string}> $lots
     * @return array{updates: array<int, float>, rest: float} neue Restmengen je Chargen-ID, nicht gedeckter Rest
     */
    public static function consumeFifo(array $lots, float $amount): array
    {
        $updates = [];
        foreach ($lots as $l) {
            if ($amount <= self::EPS) {
                break;
            }
            $rem = (float) $l['remaining'];
            if ($rem <= 0) {
                continue;
            }
            $take = min($rem, $amount);
            $updates[(int) $l['id']] = round($rem - $take, 3);
            $amount -= $take;
        }
        return ['updates' => $updates, 'rest' => max(0.0, round($amount, 3))];
    }

    /**
     * Chargen eines Einkaufs mit dem aktuellen Stand der Posten abgleichen. Schon Verbrauchtes bleibt verbraucht:
     * neue Restmenge = neue Menge − (alte Menge − alter Rest). Schlüssel ist das Produkt (Posten-IDs sind instabil).
     * @param array<int, array{id:int, product_id:?int, quantity:float|string, remaining:float|string}> $existing
     * @param array<int, array{shopping_item_id:int, quantity:float, expires_on:?string}> $wanted Produkt-ID => Soll
     * @return array{insert: array<int, array>, update: array<int, array>, delete: int[]}
     */
    public static function syncLots(array $existing, array $wanted): array
    {
        $out = ['insert' => [], 'update' => [], 'delete' => []];
        $seen = [];
        foreach ($existing as $lot) {
            $pid = (int) ($lot['product_id'] ?? 0);
            if (!$pid || !isset($wanted[$pid]) || isset($seen[$pid])) {
                $out['delete'][] = (int) $lot['id'];
                continue;
            }
            $seen[$pid] = true;
            $w = $wanted[$pid];
            $consumed = max(0.0, (float) $lot['quantity'] - (float) $lot['remaining']);
            $out['update'][(int) $lot['id']] = [
                'shopping_item_id' => $w['shopping_item_id'],
                'quantity'         => round($w['quantity'], 3),
                'remaining'        => round(max(0.0, $w['quantity'] - $consumed), 3),
                'expires_on'       => $w['expires_on'],
            ];
        }
        foreach ($wanted as $pid => $w) {
            if (!isset($seen[$pid])) {
                $out['insert'][$pid] = $w;
            }
        }
        return $out;
    }

    /**
     * Zustand eines Postens: staple (Grundvorrat), ok, low (≤ Mindestbestand), expired (abgelaufen, nichts mehr da),
     * out (aufgebraucht) oder none (nie im Vorrat gewesen).
     */
    public static function status(float $stock, float $minStock, bool $hasHistory, bool $staple, ?string $expiredOn): string
    {
        if ($staple) {
            return 'staple';
        }
        if ($stock > self::EPS) {
            return $minStock > 0 && $stock <= $minStock + self::EPS ? 'low' : 'ok';
        }
        if (!$hasHistory) {
            return 'none';
        }
        return $expiredOn !== null ? 'expired' : 'out';
    }

    /** Muss der Posten automatisch nachgekauft werden? */
    public static function needsRestock(string $status, bool $autoRestock): bool
    {
        return $autoRestock && in_array($status, ['low', 'out', 'expired'], true);
    }

    /**
     * Abgleich der Auto-Liste.
     * @param array<int, array{needs:bool, tracked:bool, depleted_at:?string}> $items Posten-ID => Zustand
     * @param array<int, array{id:int|string, done_at:?string}> $entries Posten-ID => Eintrag der Auto-Liste
     * @return array{add: int[], remove: int[]} hinzuzufügende Posten-IDs, zu löschende Eintrag-IDs
     */
    public static function autoListDiff(array $items, array $entries): array
    {
        $add = [];
        $remove = [];
        foreach ($items as $itemId => $i) {
            $e = $entries[$itemId] ?? null;
            if ($i['needs']) {
                // Abgehakter Eintrag: erst wieder aufnehmen, wenn der Posten danach (erneut) ausgegangen ist
                if (!$e || ($e['done_at'] !== null && $i['depleted_at'] !== null && $e['done_at'] < $i['depleted_at'])) {
                    $add[] = (int) $itemId;
                }
            } elseif ($e && $e['done_at'] === null && $i['tracked']) {
                $remove[] = (int) $e['id'];
            }
        }
        return ['add' => $add, 'remove' => $remove];
    }

    /**
     * Menge für die Einkaufsliste: auf ganze Referenzmengen aufgerundet, in der Einheit der Referenz
     * (Butter 250 g, es fehlen 100 g → 250 g; Eier 1 Stück, es fehlen 3 → 3).
     * @return array{0:float, 1:?string} Menge, Einheit (null = Stück)
     */
    public static function shoppingQuantity(float $missingRef, float $refQty, ?string $refUnit): array
    {
        $refs = max(1, (int) ceil($missingRef - self::EPS));
        return [round($refs * $refQty, 3), self::unit($refUnit)];
    }

    /** „1,5 l“, „3 Stück“, „250 g“ – Referenzeinheiten in der Einheit der Referenz */
    public static function label(float $ref, float $refQty, ?string $refUnit): string
    {
        $unit = self::unit($refUnit);
        $q = rtrim(rtrim(number_format($ref * $refQty, 3, ',', '.'), '0'), ',');
        return $q . ' ' . ($unit ?? 'Stück');
    }

    // ---- Datenbank -------------------------------------------------------------------

    /** Chargen eines Einkaufs neu abgleichen (nach Speichern/Bearbeiten oder neuer Zuordnung) */
    public static function syncPurchase(int $householdId, int $purchaseId): void
    {
        $repo = new InventoryRepository();
        $date = $repo->purchaseDate($purchaseId, $householdId);
        if ($date === null) {
            return;
        }
        $items = (new PurchaseRepository())->items($purchaseId);
        $productIds = array_values(array_unique(array_filter(array_map(fn ($i) => (int) $i['product_id'], $items))));
        $map = $repo->mappingForProducts($householdId, $productIds);

        $wanted = [];
        foreach ($items as $i) {
            $m = $map[(int) $i['product_id']] ?? null;
            if (!$m || (float) $i['quantity'] <= 0) {
                continue;
            }
            $amount = self::purchaseAmount((float) $i['quantity'], $i['unit'], $m['factor'], $m['ref_quantity'], $m['ref_unit']);
            $pid = (int) $i['product_id'];
            $wanted[$pid] ??= [
                'shopping_item_id' => $m['item_id'], 'quantity' => 0.0,
                'expires_on' => $m['shelf_days'] !== null ? date('Y-m-d', strtotime("$date +{$m['shelf_days']} days")) : null,
            ];
            $wanted[$pid]['quantity'] += $amount;
        }

        $diff = self::syncLots($repo->lotsForPurchase($purchaseId), $wanted);
        $repo->transaction(function () use ($repo, $diff, $householdId, $purchaseId, $date) {
            foreach ($diff['delete'] as $id) {
                $repo->deleteLot($id, $householdId);
            }
            foreach ($diff['update'] as $id => $data) {
                $repo->updateLot($id, $householdId, $data);
            }
            foreach ($diff['insert'] as $pid => $w) {
                $repo->insertLot([
                    'household_id' => $householdId, 'shopping_item_id' => $w['shopping_item_id'], 'product_id' => $pid,
                    'purchase_id' => $purchaseId, 'quantity' => round($w['quantity'], 3), 'remaining' => round($w['quantity'], 3),
                    'acquired_on' => $date, 'expires_on' => $w['expires_on'], 'source' => 'purchase',
                ]);
            }
        });
    }

    /** Einkäufe der letzten Tage (optional nur mit einem Produkt) neu abgleichen, z. B. nach neuer Zuordnung */
    public static function rebuild(int $householdId, int $days = 90, ?int $productId = null): int
    {
        $ids = (new InventoryRepository())->purchaseIdsSince($householdId, date('Y-m-d', strtotime("-$days days")), $productId);
        foreach ($ids as $id) {
            self::syncPurchase($householdId, $id);
        }
        return count($ids);
    }

    /**
     * Verbrauch abziehen (zuerst ablaufende Chargen zuerst).
     * @return float nicht gedeckter Rest
     */
    public static function consume(int $householdId, int $itemId, float $amount): float
    {
        $repo = new InventoryRepository();
        $r = self::consumeFifo($repo->activeLots($householdId, $itemId, date('Y-m-d')), $amount);
        foreach ($r['updates'] as $id => $remaining) {
            $repo->updateLot($id, $householdId, ['remaining' => $remaining]);
        }
        return $r['rest'];
    }

    /** Bestand von Hand auf einen Wert setzen: mehr = neue Charge, weniger = Verbrauch */
    public static function adjust(int $householdId, array $item, float $target): void
    {
        $repo = new InventoryRepository();
        $itemId = (int) $item['id'];
        $stock = (float) array_sum(array_column($repo->activeLots($householdId, $itemId, date('Y-m-d')), 'remaining'));
        $target = max(0.0, round($target, 3));
        if ($target > $stock + self::EPS) {
            $days = $item['shelf_days'] !== null ? (int) $item['shelf_days'] : null;
            $repo->insertLot([
                'household_id' => $householdId, 'shopping_item_id' => $itemId, 'quantity' => round($target - $stock, 3),
                'remaining' => round($target - $stock, 3), 'acquired_on' => date('Y-m-d'),
                'expires_on' => $days !== null ? date('Y-m-d', strtotime("+$days days")) : null, 'source' => 'manual',
            ]);
        } elseif ($target < $stock - self::EPS) {
            self::consume($householdId, $itemId, $stock - $target);
        }
    }

    /**
     * Auto-Liste pflegen: aufgebrauchte, abgelaufene oder knappe Posten eintragen, wieder vorrätige austragen.
     * @return int Anzahl neu eingetragener Posten
     */
    public static function refreshAutoList(int $householdId): int
    {
        $inv = new InventoryRepository();
        $shop = new ShoppingRepository();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');

        $items = [];
        $rows = [];
        foreach ($inv->stockByItem($householdId, $today) as $r) {
            $id = (int) $r['id'];
            $status = self::status((float) $r['stock'], (float) $r['min_stock'], (int) $r['lot_count'] > 0, (bool) $r['staple'], $r['expired_on']);
            $needs = self::needsRestock($status, (bool) $r['auto_restock']);
            $depleted = $r['depleted_at'];
            if ($needs && $depleted === null) {
                $depleted = $now;
                $inv->setDepleted($id, $now);
            } elseif (!$needs && $depleted !== null) {
                $depleted = null;
                $inv->setDepleted($id, null);
            }
            $items[$id] = ['needs' => $needs, 'tracked' => (int) $r['lot_count'] > 0, 'depleted_at' => $depleted];
            $rows[$id] = $r + ['status' => $status];
        }
        if (!array_filter($items, fn ($i) => $i['needs']) && !$shop->autoListExists($householdId)) {
            return 0;
        }

        $list = $shop->autoList($householdId);
        $listId = (int) $list['id'];
        $diff = self::autoListDiff($items, $shop->entriesByItem($listId));
        foreach ($diff['remove'] as $entryId) {
            $shop->deleteEntry($entryId, $listId);
        }
        foreach ($diff['add'] as $itemId) {
            $r = $rows[$itemId];
            $missing = max(1.0, (float) $r['min_stock'] - (float) $r['stock']);
            [$qty, $unit] = self::shoppingQuantity($missing, (float) $r['ref_quantity'], $r['ref_unit']);
            $note = match ($r['status']) {
                'expired' => 'abgelaufen am ' . date_de($r['expired_on']),
                'low'     => 'nur noch ' . self::label((float) $r['stock'], (float) $r['ref_quantity'], $r['ref_unit']),
                default   => 'aufgebraucht',
            };
            $shop->addEntry($listId, $itemId, $qty, $unit, $note);
        }
        return count($diff['add']);
    }
}
