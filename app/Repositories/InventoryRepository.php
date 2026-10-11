<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Vorrat: Chargen (inventory_lots) je virtuellem Posten. Mengen in Referenzeinheiten des Postens
 * (1 = einmal die Referenzmenge, z. B. 1 l Milch). Abgelaufene Chargen zählen nicht mehr zum Bestand.
 */
final class InventoryRepository extends Repository
{
    /**
     * Alle virtuellen Posten mit Bestand, Anzahl Chargen (Historie), nächstem Ablauf und – falls die jüngste Charge
     * abgelaufen ist, ohne aufgebraucht zu sein – deren Ablaufdatum (sonst wurde der Posten aufgebraucht).
     */
    public function stockByItem(int $householdId, string $today): array
    {
        return $this->many(
            'SELECT s.*, c.name AS category_name, c.color AS category_color, c.icon AS category_icon,
                    COALESCE(SUM(CASE WHEN l.expires_on IS NULL OR l.expires_on >= ? THEN l.remaining END), 0) AS stock,
                    COUNT(l.id) AS lot_count,
                    MIN(CASE WHEN l.expires_on >= ? AND l.remaining > 0 THEN l.expires_on END) AS next_expiry,
                    (SELECT CASE WHEN j.expires_on < ? AND j.remaining > 0 THEN j.expires_on END FROM inventory_lots j
                     WHERE j.shopping_item_id = s.id ORDER BY j.acquired_on DESC, j.id DESC LIMIT 1) AS expired_on
             FROM shopping_items s
             LEFT JOIN categories c ON c.id = s.category_id
             LEFT JOIN inventory_lots l ON l.shopping_item_id = s.id
             WHERE s.household_id = ?
             GROUP BY s.id ORDER BY s.name',
            [$today, $today, $today, $householdId]
        );
    }

    /** Bestand je Posten-ID (nur Posten mit Bestand > 0) */
    public function stockMap(int $householdId, string $today): array
    {
        $out = [];
        foreach ($this->many(
            'SELECT shopping_item_id, SUM(remaining) AS stock FROM inventory_lots
             WHERE household_id = ? AND (expires_on IS NULL OR expires_on >= ?) AND remaining > 0
             GROUP BY shopping_item_id',
            [$householdId, $today]
        ) as $r) {
            $out[(int) $r['shopping_item_id']] = (float) $r['stock'];
        }
        return $out;
    }

    /** Nicht abgelaufene Chargen mit Rest, in Verbrauchsreihenfolge (zuerst ablaufend, dann älteste) */
    public function activeLots(int $householdId, int $itemId, string $today): array
    {
        return $this->many(
            'SELECT * FROM inventory_lots
             WHERE household_id = ? AND shopping_item_id = ? AND remaining > 0 AND (expires_on IS NULL OR expires_on >= ?)
             ORDER BY expires_on IS NULL, expires_on, acquired_on, id',
            [$householdId, $itemId, $today]
        );
    }

    /** Chargen je Posten für die Anzeige (neueste zuerst) mit Produkt und Geschäft (nur bei sichtbaren Einkäufen) */
    public function lots(int $householdId, array $accountIds, array $itemIds, int $limitPerItem = 8): array
    {
        if (!$itemIds) {
            return [];
        }
        $itemIds = array_values($itemIds);
        $out = [];
        foreach ($this->many(
            'SELECT l.*, pr.name AS product_name, p.store, p.id AS visible_purchase_id
             FROM inventory_lots l LEFT JOIN products pr ON pr.id = l.product_id
             LEFT JOIN purchases p ON p.id = l.purchase_id AND (p.account_id IS NULL OR p.account_id IN (' . self::in($accountIds) . '))
             WHERE l.household_id = ? AND l.shopping_item_id IN (' . self::in($itemIds) . ')
             ORDER BY l.acquired_on DESC, l.id DESC',
            [...$accountIds, $householdId, ...$itemIds]
        ) as $r) {
            $list = &$out[(int) $r['shopping_item_id']];
            if (count($list ?? []) < $limitPerItem) {
                $list[] = $r;
            }
            unset($list);
        }
        return $out;
    }

    public function findLot(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM inventory_lots WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function lotsForPurchase(int $purchaseId): array
    {
        return $this->many('SELECT * FROM inventory_lots WHERE purchase_id = ? ORDER BY id', [$purchaseId]);
    }

    public function insertLot(array $data): int
    {
        return $this->insert('inventory_lots', $data);
    }

    public function updateLot(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('inventory_lots', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function deleteLot(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM inventory_lots WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /**
     * Zuordnung Produkt → (erster) virtueller Posten mit Faktor, Verbrauchsdauer und Referenz.
     * @return array<int, array{item_id:int, factor:float, shelf_days:?int, ref_quantity:float, ref_unit:?string}>
     */
    public function mappingForProducts(int $householdId, array $productIds): array
    {
        if (!$productIds) {
            return [];
        }
        $productIds = array_values($productIds);
        $out = [];
        foreach ($this->many(
            'SELECT sip.product_id, s.id AS item_id, sip.factor, COALESCE(sip.shelf_days, s.shelf_days) AS shelf_days,
                    s.ref_quantity, s.ref_unit
             FROM shopping_item_products sip JOIN shopping_items s ON s.id = sip.shopping_item_id
             WHERE s.household_id = ? AND sip.product_id IN (' . self::in($productIds) . ')
             ORDER BY s.id',
            [$householdId, ...$productIds]
        ) as $r) {
            $out[(int) $r['product_id']] ??= [
                'item_id' => (int) $r['item_id'], 'factor' => (float) $r['factor'],
                'shelf_days' => $r['shelf_days'] !== null ? (int) $r['shelf_days'] : null,
                'ref_quantity' => (float) $r['ref_quantity'], 'ref_unit' => $r['ref_unit'],
            ];
        }
        return $out;
    }

    /** Einkäufe ab einem Datum, optional nur solche mit einem bestimmten Produkt */
    public function purchaseIdsSince(int $householdId, string $since, ?int $productId = null): array
    {
        $sql = 'SELECT DISTINCT p.id FROM purchases p';
        $params = [];
        if ($productId) {
            $sql .= ' JOIN purchase_items i ON i.purchase_id = p.id AND i.product_id = ?';
            $params[] = $productId;
        }
        $sql .= ' WHERE p.household_id = ? AND p.purchase_date >= ? ORDER BY p.id';
        return array_map('intval', array_column($this->many($sql, [...$params, $householdId, $since]), 'id'));
    }

    public function purchaseDate(int $purchaseId, int $householdId): ?string
    {
        $d = $this->value('SELECT purchase_date FROM purchases WHERE id = ? AND household_id = ?', [$purchaseId, $householdId]);
        return $d !== null ? (string) $d : null;
    }

    public function setDepleted(int $itemId, ?string $at): void
    {
        $this->exec('UPDATE shopping_items SET depleted_at = ? WHERE id = ?', [$at, $itemId]);
    }
}
