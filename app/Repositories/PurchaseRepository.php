<?php

declare(strict_types=1);

namespace App\Repositories;

final class PurchaseRepository extends Repository
{
    /** Einkäufe ohne Konto sind für alle sichtbar, sonst nur mit Leserecht auf das Konto */
    private function visibleSql(array $accountIds): string
    {
        return '(p.account_id IS NULL OR p.account_id IN (' . self::in($accountIds) . '))';
    }

    public function search(int $householdId, array $accountIds, array $f, int $limit = 30, int $offset = 0): array
    {
        $where = ['p.household_id = ?', $this->visibleSql($accountIds)];
        $params = [$householdId, ...$accountIds];
        if (!empty($f['q'])) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
            $where[] = '(p.store LIKE ? OR EXISTS (SELECT 1 FROM purchase_items i WHERE i.purchase_id = p.id AND i.name LIKE ?))';
            $params[] = $like;
            $params[] = $like;
        }
        if (!empty($f['from'])) {
            $where[] = 'p.purchase_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 'p.purchase_date <= ?';
            $params[] = $f['to'];
        }
        $params[] = $limit;
        $params[] = $offset;
        return $this->many(
            'SELECT p.*, a.name AS account_name, u.name AS user_name,
                    (SELECT COUNT(*) FROM purchase_items i WHERE i.purchase_id = p.id) AS item_count
             FROM purchases p LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN users u ON u.id = p.created_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY p.purchase_date DESC, p.id DESC LIMIT ? OFFSET ?',
            $params
        );
    }

    public function monthTotal(int $householdId, array $accountIds, string $from, string $to): array
    {
        return $this->one(
            'SELECT COUNT(*) AS cnt, COALESCE(SUM(p.total), 0) AS total FROM purchases p
             WHERE p.household_id = ? AND ' . $this->visibleSql($accountIds) . ' AND p.purchase_date BETWEEN ? AND ?',
            [$householdId, ...$accountIds, $from, $to]
        );
    }

    public function find(int $id, int $householdId, array $accountIds): ?array
    {
        return $this->one(
            'SELECT p.*, a.name AS account_name, t.booking_date AS tx_date, t.amount AS tx_amount, t.payee AS tx_payee
             FROM purchases p LEFT JOIN accounts a ON a.id = p.account_id LEFT JOIN transactions t ON t.id = p.transaction_id
             WHERE p.id = ? AND p.household_id = ? AND ' . $this->visibleSql($accountIds),
            [$id, $householdId, ...$accountIds]
        );
    }

    public function items(int $purchaseId): array
    {
        return $this->many(
            'SELECT i.*, c.name AS category_name, c.color AS category_color, c.icon AS category_icon, pc.name AS category_parent
             FROM purchase_items i LEFT JOIN categories c ON c.id = i.category_id LEFT JOIN categories pc ON pc.id = c.parent_id
             WHERE i.purchase_id = ? ORDER BY i.sort_order, i.id',
            [$purchaseId]
        );
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('purchases', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('purchases', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM purchases WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function replaceItems(int $purchaseId, array $items): void
    {
        $this->exec('DELETE FROM purchase_items WHERE purchase_id = ?', [$purchaseId]);
        foreach (array_values($items) as $i => $item) {
            $this->insert('purchase_items', ['purchase_id' => $purchaseId, 'sort_order' => $i] + $item);
        }
    }
}
