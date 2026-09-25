<?php

declare(strict_types=1);

namespace App\Repositories;

final class LoanRepository extends Repository
{
    public function all(int $householdId): array
    {
        return $this->many(
            'SELECT l.*, a.name AS account_name FROM loans l LEFT JOIN accounts a ON a.id = l.account_id
             WHERE l.household_id = ? ORDER BY l.payout_date',
            [$householdId]
        );
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one(
            'SELECT l.*, a.name AS account_name FROM loans l LEFT JOIN accounts a ON a.id = l.account_id WHERE l.id = ? AND l.household_id = ?',
            [$id, $householdId]
        );
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('loans', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('loans', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM loans WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function specials(int $loanId): array
    {
        return $this->many('SELECT * FROM loan_special_payments WHERE loan_id = ? ORDER BY payment_date', [$loanId]);
    }

    public function addSpecial(int $loanId, string $date, string $amount, ?string $note): void
    {
        $this->insert('loan_special_payments', ['loan_id' => $loanId, 'payment_date' => $date, 'amount' => $amount, 'note' => $note]);
    }

    public function deleteSpecial(int $loanId, int $id): void
    {
        $this->exec('DELETE FROM loan_special_payments WHERE id = ? AND loan_id = ?', [$id, $loanId]);
    }

    public function changes(int $loanId): array
    {
        return $this->many('SELECT * FROM loan_rate_changes WHERE loan_id = ? ORDER BY valid_from', [$loanId]);
    }

    public function addChange(int $loanId, string $validFrom, ?string $rate, ?string $payment, ?string $note): void
    {
        $this->insert('loan_rate_changes', [
            'loan_id' => $loanId, 'valid_from' => $validFrom, 'interest_rate' => $rate, 'monthly_payment' => $payment, 'note' => $note,
        ]);
    }

    public function deleteChange(int $loanId, int $id): void
    {
        $this->exec('DELETE FROM loan_rate_changes WHERE id = ? AND loan_id = ?', [$id, $loanId]);
    }
}
