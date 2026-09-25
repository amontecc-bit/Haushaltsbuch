<?php

declare(strict_types=1);

namespace App\Repositories;

final class TransactionRepository extends Repository
{
    /**
     * Gefilterte Liste.
     * $f: account_id, category_id ('none' = ohne Kategorie), from, to, q, type (income|expense), user_id
     */
    public function search(int $householdId, array $accountIds, array $f, int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->filterSql($householdId, $accountIds, $f);
        $params[] = $limit;
        $params[] = $offset;
        return $this->many(
            "SELECT t.*, a.name AS account_name, a.color AS account_color,
                    c.name AS category_name, c.icon AS category_icon, c.color AS category_color, pc.name AS category_parent,
                    ta.name AS transfer_account_name, u.name AS user_name,
                    (SELECT p.id FROM purchases p WHERE p.transaction_id = t.id LIMIT 1) AS purchase_id
             FROM transactions t
             JOIN accounts a ON a.id = t.account_id
             LEFT JOIN categories c ON c.id = t.category_id
             LEFT JOIN categories pc ON pc.id = c.parent_id
             LEFT JOIN users u ON u.id = t.created_by
             LEFT JOIN transactions tt ON tt.transfer_group = t.transfer_group AND tt.id <> t.id
             LEFT JOIN accounts ta ON ta.id = tt.account_id
             WHERE $where
             ORDER BY t.booking_date DESC, t.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    /** @return array{count:int, income:string, expense:string} */
    public function summary(int $householdId, array $accountIds, array $f): array
    {
        [$where, $params] = $this->filterSql($householdId, $accountIds, $f);
        $row = $this->one(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN t.amount > 0 AND t.transfer_group IS NULL THEN t.amount END), 0) AS income,
                    COALESCE(SUM(CASE WHEN t.amount < 0 AND t.transfer_group IS NULL THEN t.amount END), 0) AS expense
             FROM transactions t WHERE $where",
            $params
        );
        return ['count' => (int) $row['cnt'], 'income' => $row['income'], 'expense' => $row['expense']];
    }

    private function filterSql(int $householdId, array $accountIds, array $f): array
    {
        $where = ['t.household_id = ?', 't.account_id IN (' . self::in($accountIds) . ')'];
        $params = [$householdId, ...$accountIds];

        if (!empty($f['account_id'])) {
            $where[] = 't.account_id = ?';
            $params[] = (int) $f['account_id'];
        }
        if (($f['category_id'] ?? '') === 'none') {
            $where[] = 't.category_id IS NULL AND t.transfer_group IS NULL';
        } elseif (!empty($f['category_id'])) {
            $where[] = '(t.category_id = ? OR t.category_id IN (SELECT id FROM categories WHERE parent_id = ?))';
            $params[] = (int) $f['category_id'];
            $params[] = (int) $f['category_id'];
        }
        if (!empty($f['from'])) {
            $where[] = 't.booking_date >= ?';
            $params[] = $f['from'];
        }
        if (!empty($f['to'])) {
            $where[] = 't.booking_date <= ?';
            $params[] = $f['to'];
        }
        if (($f['type'] ?? '') === 'income') {
            $where[] = 't.amount > 0';
        } elseif (($f['type'] ?? '') === 'expense') {
            $where[] = 't.amount < 0';
        } elseif (($f['type'] ?? '') === 'transfer') {
            $where[] = 't.transfer_group IS NOT NULL';
        }
        if (!empty($f['user_id'])) {
            $where[] = 't.created_by = ?';
            $params[] = (int) $f['user_id'];
        }
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], $f['q']) . '%';
            $where[] = '(t.payee LIKE ? OR t.purpose LIKE ? OR t.note LIKE ?)';
            array_push($params, $q, $q, $q);
        }
        return [implode(' AND ', $where), $params];
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM transactions WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('transactions', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('transactions', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM transactions WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function transferPartner(array $tx): ?array
    {
        if (!$tx['transfer_group']) {
            return null;
        }
        return $this->one('SELECT * FROM transactions WHERE transfer_group = ? AND id <> ?', [$tx['transfer_group'], $tx['id']]);
    }

    public function deleteTransferGroup(string $group, int $householdId): void
    {
        $this->exec('DELETE FROM transactions WHERE transfer_group = ? AND household_id = ?', [$group, $householdId]);
    }

    public function hashExists(int $accountId, string $hash): bool
    {
        return (bool) $this->value('SELECT 1 FROM transactions WHERE account_id = ? AND import_hash = ? LIMIT 1', [$accountId, $hash]);
    }

    /**
     * Vorhandene Buchung (z.B. aus Dauerauftrag, manuell) mit gleichem Betrag in einem Datumsfenster,
     * die noch nicht aus einem Import stammt.
     */
    public function findMatchForImport(int $accountId, string $amount, string $date, int $days = 5): ?array
    {
        return $this->one(
            "SELECT * FROM transactions
             WHERE account_id = ? AND amount = ? AND import_hash IS NULL
               AND booking_date BETWEEN DATE_SUB(?, INTERVAL ? DAY) AND DATE_ADD(?, INTERVAL ? DAY)
             ORDER BY ABS(DATEDIFF(booking_date, ?)) LIMIT 1",
            [$accountId, $amount, $date, $days, $date, $days, $date]
        );
    }

    /** Häufigste Kategorie bei gleichem Empfänger (für Vorschläge) */
    public function mostUsedCategoryForPayee(int $householdId, string $payee): ?int
    {
        $v = $this->value(
            'SELECT category_id FROM transactions
             WHERE household_id = ? AND payee = ? AND category_id IS NOT NULL
             GROUP BY category_id ORDER BY COUNT(*) DESC, MAX(booking_date) DESC LIMIT 1',
            [$householdId, $payee]
        );
        return $v ? (int) $v : null;
    }

    /** Kandidaten zum Verknüpfen mit einem Einkauf */
    public function candidatesForPurchase(int $householdId, array $accountIds, string $date, ?string $amount): array
    {
        $params = [$householdId, ...$accountIds, $date, $date];
        $amountSql = '';
        if ($amount !== null) {
            $amountSql = 'ORDER BY ABS(t.amount + ?) ASC, ABS(DATEDIFF(t.booking_date, ?)) ASC';
            $params[] = $amount;
            $params[] = $date;
        } else {
            $amountSql = 'ORDER BY ABS(DATEDIFF(t.booking_date, ?)) ASC';
            $params[] = $date;
        }
        return $this->many(
            'SELECT t.id, t.booking_date, t.amount, t.payee, a.name AS account_name
             FROM transactions t JOIN accounts a ON a.id = t.account_id
             WHERE t.household_id = ? AND t.account_id IN (' . self::in($accountIds) . ')
               AND t.amount < 0 AND t.transfer_group IS NULL
               AND t.booking_date BETWEEN DATE_SUB(?, INTERVAL 7 DAY) AND DATE_ADD(?, INTERVAL 10 DAY)
               AND NOT EXISTS (SELECT 1 FROM purchases p WHERE p.transaction_id = t.id) ' . $amountSql . ' LIMIT 10',
            $params
        );
    }

    public function recentPayees(int $householdId, array $accountIds, int $limit = 200): array
    {
        return $this->many(
            'SELECT payee, COUNT(*) AS n FROM transactions
             WHERE household_id = ? AND account_id IN (' . self::in($accountIds) . ') AND CHAR_LENGTH(payee) > 0
             GROUP BY payee ORDER BY n DESC LIMIT ' . (int) $limit,
            [$householdId, ...$accountIds]
        );
    }
}
