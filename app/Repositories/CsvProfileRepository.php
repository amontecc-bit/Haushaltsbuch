<?php

declare(strict_types=1);

namespace App\Repositories;

final class CsvProfileRepository extends Repository
{
    /** Eigene Profile zuerst, dann die mitgelieferten */
    public function all(int $householdId): array
    {
        return $this->many(
            'SELECT * FROM csv_profiles WHERE household_id = ? OR household_id IS NULL ORDER BY household_id IS NULL, name',
            [$householdId]
        );
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM csv_profiles WHERE id = ? AND (household_id = ? OR household_id IS NULL)', [$id, $householdId]);
    }

    public function save(int $householdId, string $name, string $delimiter, string $decimalSep, array $mapping): int
    {
        $existing = $this->value('SELECT id FROM csv_profiles WHERE household_id = ? AND name = ?', [$householdId, $name]);
        $json = json_encode($mapping, JSON_UNESCAPED_UNICODE);
        if ($existing) {
            $this->exec('UPDATE csv_profiles SET delimiter = ?, decimal_sep = ?, mapping = ? WHERE id = ?', [$delimiter, $decimalSep, $json, $existing]);
            return (int) $existing;
        }
        return $this->insert('csv_profiles', [
            'household_id' => $householdId, 'name' => $name, 'delimiter' => $delimiter,
            'decimal_sep' => $decimalSep, 'mapping' => $json,
        ]);
    }

    public function createBatch(int $householdId, int $accountId, string $filename, int $userId): int
    {
        return $this->insert('import_batches', [
            'household_id' => $householdId, 'account_id' => $accountId, 'filename' => mb_substr($filename, 0, 255), 'created_by' => $userId,
        ]);
    }

    public function finishBatch(int $id, int $total, int $imported, int $skipped): void
    {
        $this->exec('UPDATE import_batches SET rows_total = ?, rows_imported = ?, rows_skipped = ? WHERE id = ?', [$total, $imported, $skipped, $id]);
    }

    public function recentBatches(int $householdId, array $accountIds): array
    {
        return $this->many(
            'SELECT b.*, a.name AS account_name, u.name AS user_name FROM import_batches b
             JOIN accounts a ON a.id = b.account_id LEFT JOIN users u ON u.id = b.created_by
             WHERE b.household_id = ? AND b.account_id IN (' . self::in($accountIds) . ')
             ORDER BY b.created_at DESC LIMIT 10',
            [$householdId, ...$accountIds]
        );
    }
}
