<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductRepository extends Repository
{
    /** @return array<string, array> normalized => Produkt */
    public function indexByNormalized(int $householdId): array
    {
        $out = [];
        foreach ($this->many('SELECT id, name, normalized, default_category_id FROM products WHERE household_id = ?', [$householdId]) as $p) {
            $out[$p['normalized']] = $p;
        }
        return $out;
    }

    /**
     * Produkt anlegen oder aktualisieren; die gewählte Kategorie wird als Standard gelernt.
     */
    public function upsert(int $householdId, string $name, string $normalized, ?int $categoryId): int
    {
        $existing = $this->one('SELECT id, default_category_id FROM products WHERE household_id = ? AND normalized = ?', [$householdId, $normalized]);
        if ($existing) {
            if ($categoryId && (int) $existing['default_category_id'] !== $categoryId) {
                $this->exec('UPDATE products SET default_category_id = ? WHERE id = ?', [$categoryId, $existing['id']]);
            }
            return (int) $existing['id'];
        }
        return $this->insert('products', [
            'household_id'        => $householdId,
            'name'                => mb_substr($name, 0, 190),
            'normalized'          => mb_substr($normalized, 0, 190),
            'default_category_id' => $categoryId,
        ]);
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM products WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /** Für Autovervollständigung im Posten-Formular */
    public function names(int $householdId, int $limit = 500): array
    {
        return $this->many(
            'SELECT p.name, p.default_category_id AS category_id, COUNT(i.id) AS n,
                    (SELECT i2.unit_price FROM purchase_items i2 JOIN purchases pu ON pu.id = i2.purchase_id
                     WHERE i2.product_id = p.id ORDER BY pu.purchase_date DESC LIMIT 1) AS last_price
             FROM products p LEFT JOIN purchase_items i ON i.product_id = p.id
             WHERE p.household_id = ? GROUP BY p.id ORDER BY n DESC, p.name LIMIT ' . (int) $limit,
            [$householdId]
        );
    }
}
