<?php

declare(strict_types=1);

namespace App\Repositories;

final class AccountRepository extends Repository
{
    /**
     * Konten inkl. aktuellem Saldo (bis heute) für die übergebenen IDs.
     */
    public function withBalances(int $householdId, array $ids, bool $includeArchived = false, ?string $asOf = null): array
    {
        if (!$ids) {
            return [];
        }
        $asOf ??= date('Y-m-d');
        $archived = $includeArchived ? '' : 'AND a.archived = 0';
        return $this->many(
            'SELECT a.*, a.opening_balance + COALESCE((SELECT SUM(t.amount) FROM transactions t
                     WHERE t.account_id = a.id AND t.booking_date <= ?), 0) AS balance
             FROM accounts a
             WHERE a.household_id = ? AND a.id IN (' . self::in($ids) . ") $archived
             ORDER BY a.archived, a.sort_order, a.name",
            [$asOf, $householdId, ...$ids]
        );
    }

    /** Einfache Liste für Auswahlfelder */
    public function options(int $householdId, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        return $this->many(
            'SELECT id, name, type, color FROM accounts WHERE household_id = ? AND archived = 0 AND id IN (' . self::in($ids) . ')
             ORDER BY sort_order, name',
            [$householdId, ...$ids]
        );
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM accounts WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function balance(int $accountId, ?string $asOf = null): string
    {
        return (string) $this->value(
            'SELECT a.opening_balance + COALESCE((SELECT SUM(amount) FROM transactions WHERE account_id = a.id AND booking_date <= ?), 0)
             FROM accounts a WHERE a.id = ?',
            [$asOf ?? date('Y-m-d'), $accountId]
        );
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('accounts', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('accounts', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM accounts WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function transactionCount(int $id): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM transactions WHERE account_id = ?', [$id]);
    }

    /** @return array<int, array{can_view:int, can_book:int}> user_id => Rechte */
    public function permissions(int $accountId): array
    {
        $rows = $this->many('SELECT user_id, can_view, can_book FROM account_permissions WHERE account_id = ?', [$accountId]);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['user_id']] = ['can_view' => (int) $r['can_view'], 'can_book' => (int) $r['can_book']];
        }
        return $out;
    }

    /** @param array<int, array{can_view:bool, can_book:bool}> $perms */
    public function setPermissions(int $accountId, array $perms): void
    {
        $this->exec('DELETE FROM account_permissions WHERE account_id = ?', [$accountId]);
        foreach ($perms as $userId => $p) {
            if (!$p['can_view'] && !$p['can_book']) {
                continue;
            }
            $this->insert('account_permissions', [
                'account_id' => $accountId,
                'user_id'    => $userId,
                'can_view'   => 1, // wer buchen darf, darf auch sehen
                'can_book'   => $p['can_book'] ? 1 : 0,
            ]);
        }
    }

    /** Konto per IBAN finden (für CSV-Import-Zuordnung) */
    public function findByIban(int $householdId, string $iban): ?array
    {
        $iban = strtoupper(str_replace(' ', '', $iban));
        return $this->one("SELECT * FROM accounts WHERE household_id = ? AND REPLACE(UPPER(iban), ' ', '') = ?", [$householdId, $iban]);
    }
}
