<?php

declare(strict_types=1);

namespace App\Repositories;

final class RecurringRepository extends Repository
{
    public function all(int $householdId, array $accountIds, bool $onlyActive = false): array
    {
        $active = $onlyActive ? 'AND r.active = 1' : '';
        return $this->many(
            'SELECT r.*, a.name AS account_name, a.color AS account_color, ta.name AS to_account_name,
                    c.name AS category_name, c.icon AS category_icon, c.color AS category_color, ca.name AS counterpart_account_name
             FROM recurring_transactions r
             JOIN accounts a ON a.id = r.account_id
             LEFT JOIN accounts ta ON ta.id = r.to_account_id
             LEFT JOIN categories c ON c.id = r.category_id
             LEFT JOIN recurring_transactions cp ON cp.id = r.counterpart_id
             LEFT JOIN accounts ca ON ca.id = cp.account_id
             WHERE r.household_id = ? AND r.account_id IN (' . self::in($accountIds) . ") $active
             ORDER BY r.active DESC, r.amount > 0 DESC, ABS(r.amount) DESC",
            [$householdId, ...$accountIds]
        );
    }

    /** Aktive Vorlagen eines Haushalts ohne Rechteprüfung (für Hintergrund-Abgleiche) */
    public function activeOfHousehold(int $householdId): array
    {
        return $this->many('SELECT * FROM recurring_transactions WHERE active = 1 AND household_id = ?', [$householdId]);
    }

    /** Alle aktiven Vorlagen aller Haushalte bzw. eines Haushalts (für automatische Buchung) */
    public function activeForBooking(?int $householdId = null): array
    {
        if ($householdId === null) {
            return $this->many('SELECT * FROM recurring_transactions WHERE active = 1 AND auto_book = 1');
        }
        return $this->many('SELECT * FROM recurring_transactions WHERE active = 1 AND auto_book = 1 AND household_id = ?', [$householdId]);
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM recurring_transactions WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function findByLoan(int $loanId): ?array
    {
        return $this->one('SELECT * FROM recurring_transactions WHERE loan_id = ?', [$loanId]);
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('recurring_transactions', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('recurring_transactions', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM recurring_transactions WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function markBooked(int $id, string $date): void
    {
        $this->exec('UPDATE recurring_transactions SET last_booked_date = ? WHERE id = ?', [$date, $id]);
    }
}
