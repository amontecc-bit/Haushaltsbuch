<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Einkaufslisten, Listeneinträge und virtuelle Posten (shopping_items) mit ihren zugeordneten Produkten.
 */
final class ShoppingRepository extends Repository
{
    // ---- Listen --------------------------------------------------------------

    public function lists(int $householdId): array
    {
        return $this->many(
            'SELECT l.*, u.name AS user_name,
                    SUM(e.id IS NOT NULL AND e.done_at IS NULL) AS open_count, SUM(e.done_at IS NOT NULL) AS done_count
             FROM shopping_lists l LEFT JOIN shopping_list_entries e ON e.list_id = l.id LEFT JOIN users u ON u.id = l.created_by
             WHERE l.household_id = ? GROUP BY l.id ORDER BY l.created_at DESC, l.id DESC',
            [$householdId]
        );
    }

    public function findList(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM shopping_lists WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function createList(int $householdId, string $name, int $userId): int
    {
        return $this->insert('shopping_lists', ['household_id' => $householdId, 'name' => $name, 'created_by' => $userId ?: null]);
    }

    public function renameList(int $id, int $householdId, string $name): void
    {
        $this->updateWhere('shopping_lists', ['name' => $name], ['id' => $id, 'household_id' => $householdId]);
    }

    public function deleteList(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM shopping_lists WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    // ---- Einträge ------------------------------------------------------------

    /**
     * Einträge einer Liste mit virtuellem Posten, Kategorie und Einkauf, mit dem sie abgehakt wurden
     * (Geschäft/Datum nur bei sichtbaren Einkäufen). Die Liste vorher per findList() dem Haushalt zuordnen.
     */
    public function entries(int $listId, array $accountIds): array
    {
        return $this->many(
            'SELECT e.*, s.name, s.category_id, c.name AS category_name, c.color AS category_color, c.icon AS category_icon,
                    COALESCE(pc.sort_order, c.sort_order, 9999) AS cat_sort, COALESCE(pc.name, c.name) AS cat_group,
                    p.store AS purchase_store, p.purchase_date
             FROM shopping_list_entries e
             JOIN shopping_items s ON s.id = e.shopping_item_id
             LEFT JOIN categories c ON c.id = s.category_id LEFT JOIN categories pc ON pc.id = c.parent_id
             LEFT JOIN purchases p ON p.id = e.purchase_id AND (p.account_id IS NULL OR p.account_id IN (' . self::in($accountIds) . '))
             WHERE e.list_id = ?
             ORDER BY cat_sort, cat_group, s.name',
            [...$accountIds, $listId]
        );
    }

    public function findEntry(int $id, int $listId): ?array
    {
        return $this->one('SELECT * FROM shopping_list_entries WHERE id = ? AND list_id = ?', [$id, $listId]);
    }

    /**
     * Posten auf die Liste setzen. Steht er schon offen drauf (gleiche Einheit), wird die Menge erhöht;
     * ein bereits abgehakter Eintrag wird mit der neuen Menge wieder geöffnet.
     */
    public function addEntry(int $listId, int $itemId, float $quantity, ?string $unit, ?string $note): int
    {
        $e = $this->one('SELECT * FROM shopping_list_entries WHERE list_id = ? AND shopping_item_id = ?', [$listId, $itemId]);
        if (!$e) {
            return $this->insert('shopping_list_entries', [
                'list_id' => $listId, 'shopping_item_id' => $itemId, 'quantity' => $quantity, 'unit' => $unit, 'note' => $note,
            ]);
        }
        $add = $e['done_at'] === null && (string) $e['unit'] === (string) $unit;
        $this->exec(
            'UPDATE shopping_list_entries SET quantity = ?, unit = ?, note = COALESCE(?, note), done_at = NULL, purchase_id = NULL WHERE id = ?',
            [$add ? (float) $e['quantity'] + $quantity : $quantity, $unit, $note, $e['id']]
        );
        return (int) $e['id'];
    }

    public function updateEntry(int $id, int $listId, array $data): void
    {
        $this->updateWhere('shopping_list_entries', $data, ['id' => $id, 'list_id' => $listId]);
    }

    public function deleteEntry(int $id, int $listId): void
    {
        $this->exec('DELETE FROM shopping_list_entries WHERE id = ? AND list_id = ?', [$id, $listId]);
    }

    public function markDone(int $id, ?int $purchaseId): void
    {
        $this->exec('UPDATE shopping_list_entries SET done_at = NOW(), purchase_id = ? WHERE id = ?', [$purchaseId, $id]);
    }

    public function clearDone(int $listId): int
    {
        return $this->exec('DELETE FROM shopping_list_entries WHERE list_id = ? AND done_at IS NOT NULL', [$listId]);
    }

    /**
     * Offene Einträge für den Abgleich mit einem Einkauf, mit Namen und Produkt-IDs des virtuellen Postens.
     * $createdUntil: nur Einträge, die bis zu diesem Tag angelegt wurden.
     * @return array<int, array{id:int, shopping_item_id:int, normalized:string, product_ids:int[]}>
     */
    public function openEntriesForMatching(int $householdId, ?int $listId, ?string $createdUntil): array
    {
        $where = ['l.household_id = ?', 'e.done_at IS NULL'];
        $params = [$householdId];
        if ($listId) {
            $where[] = 'l.id = ?';
            $params[] = $listId;
        }
        if ($createdUntil) {
            $where[] = 'DATE(e.created_at) <= ?';
            $params[] = $createdUntil;
        }
        $out = [];
        foreach ($this->many(
            'SELECT e.id, e.shopping_item_id, s.normalized, GROUP_CONCAT(sip.product_id) AS product_ids
             FROM shopping_list_entries e
             JOIN shopping_lists l ON l.id = e.list_id
             JOIN shopping_items s ON s.id = e.shopping_item_id
             LEFT JOIN shopping_item_products sip ON sip.shopping_item_id = s.id
             WHERE ' . implode(' AND ', $where) . ' GROUP BY e.id',
            $params
        ) as $r) {
            $out[(int) $r['id']] = [
                'id' => (int) $r['id'], 'shopping_item_id' => (int) $r['shopping_item_id'], 'normalized' => $r['normalized'],
                'product_ids' => $r['product_ids'] ? array_map('intval', explode(',', $r['product_ids'])) : [],
            ];
        }
        return $out;
    }

    // ---- Virtuelle Posten ----------------------------------------------------

    /** Alle virtuellen Posten mit Anzahl zugeordneter Produkte */
    public function items(int $householdId): array
    {
        return $this->many(
            'SELECT s.*, c.name AS category_name, c.color AS category_color, c.icon AS category_icon,
                    (SELECT COUNT(*) FROM shopping_item_products sip WHERE sip.shopping_item_id = s.id) AS product_count
             FROM shopping_items s LEFT JOIN categories c ON c.id = s.category_id
             WHERE s.household_id = ? ORDER BY s.name',
            [$householdId]
        );
    }

    public function findItem(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM shopping_items WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function findItemByNormalized(int $householdId, string $normalized): ?array
    {
        return $this->one('SELECT * FROM shopping_items WHERE household_id = ? AND normalized = ?', [$householdId, $normalized]);
    }

    /** Erster virtueller Posten, dem das Produkt zugeordnet ist */
    public function itemForProduct(int $householdId, int $productId): ?array
    {
        return $this->one(
            'SELECT s.* FROM shopping_items s JOIN shopping_item_products sip ON sip.shopping_item_id = s.id
             WHERE s.household_id = ? AND sip.product_id = ? ORDER BY s.id LIMIT 1',
            [$householdId, $productId]
        );
    }

    public function createItem(int $householdId, string $name, string $normalized, ?int $categoryId): int
    {
        return $this->insert('shopping_items', [
            'household_id' => $householdId, 'name' => mb_substr($name, 0, 190), 'normalized' => mb_substr($normalized, 0, 190),
            'category_id' => $categoryId,
        ]);
    }

    public function updateItem(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('shopping_items', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function deleteItem(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM shopping_items WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /** Produkt zuordnen (Haushalt von Posten und Produkt vorher prüfen) */
    public function linkProduct(int $itemId, int $productId): void
    {
        $this->exec('INSERT IGNORE INTO shopping_item_products (shopping_item_id, product_id) VALUES (?, ?)', [$itemId, $productId]);
    }

    public function unlinkProduct(int $itemId, int $productId): void
    {
        $this->exec('DELETE FROM shopping_item_products WHERE shopping_item_id = ? AND product_id = ?', [$itemId, $productId]);
    }

    /** @return array<int, array<int, array{id:int, name:string}>> Posten-ID => zugeordnete Produkte */
    public function productsByItem(int $householdId, ?array $itemIds = null): array
    {
        $filter = $itemIds === null ? '' : ' AND s.id IN (' . self::in($itemIds) . ')';
        $out = [];
        foreach ($this->many(
            'SELECT sip.shopping_item_id, pr.id, pr.name FROM shopping_item_products sip
             JOIN shopping_items s ON s.id = sip.shopping_item_id JOIN products pr ON pr.id = sip.product_id
             WHERE s.household_id = ?' . $filter . ' ORDER BY pr.name',
            [$householdId, ...($itemIds ?? [])]
        ) as $r) {
            $out[(int) $r['shopping_item_id']][] = ['id' => (int) $r['id'], 'name' => $r['name']];
        }
        return $out;
    }

    /**
     * Bisheriger günstigster/teuerster Einzelpreis je virtuellem Posten über alle zugeordneten Produkte
     * (nur sichtbare Einkäufe, Pfand/Rabatte mit Preis ≤ 0 ausgenommen) und das Geschäft mit dem besten Preis.
     * @return array<int, array{min:string, max:string, cnt:int, unit:?string, min_store:?string}>
     */
    public function priceStats(int $householdId, array $accountIds, array $itemIds): array
    {
        if (!$itemIds) {
            return [];
        }
        $out = [];
        foreach ($this->many(
            "SELECT sip.shopping_item_id, MIN(i.unit_price) AS min_price, MAX(i.unit_price) AS max_price, COUNT(*) AS cnt,
                    MAX(i.unit) AS unit,
                    SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(p.store, '') ORDER BY i.unit_price, p.purchase_date DESC SEPARATOR '\n'), '\n', 1) AS min_store
             FROM shopping_item_products sip
             JOIN purchase_items i ON i.product_id = sip.product_id
             JOIN purchases p ON p.id = i.purchase_id
             WHERE p.household_id = ? AND (p.account_id IS NULL OR p.account_id IN (" . self::in($accountIds) . '))
               AND i.unit_price > 0 AND sip.shopping_item_id IN (' . self::in($itemIds) . ')
             GROUP BY sip.shopping_item_id',
            [$householdId, ...$accountIds, ...$itemIds]
        ) as $r) {
            $out[(int) $r['shopping_item_id']] = [
                'min' => $r['min_price'], 'max' => $r['max_price'], 'cnt' => (int) $r['cnt'], 'unit' => $r['unit'],
                'min_store' => $r['min_store'] !== '' ? $r['min_store'] : null,
            ];
        }
        return $out;
    }

    /** Produkte aus den Einkäufen (Fundus) mit Preisspanne – für die Auswahl beim Hinzufügen */
    public function productCatalog(int $householdId, array $accountIds, int $limit = 3000): array
    {
        return $this->many(
            'SELECT pr.id, pr.name, pr.default_category_id, COUNT(i.id) AS cnt,
                    MIN(i.unit_price) AS min_price, MAX(i.unit_price) AS max_price, MAX(i.unit) AS unit
             FROM products pr
             JOIN purchase_items i ON i.product_id = pr.id AND i.unit_price > 0
             JOIN purchases p ON p.id = i.purchase_id
             WHERE pr.household_id = ? AND (p.account_id IS NULL OR p.account_id IN (' . self::in($accountIds) . '))
             GROUP BY pr.id ORDER BY cnt DESC, pr.name LIMIT ' . (int) $limit,
            [$householdId, ...$accountIds]
        );
    }
}
