<?php

declare(strict_types=1);

namespace App\Repositories;

final class WishRepository extends Repository
{
    private const SELECT = 'SELECT w.*, u.name AS for_user_name, a.name AS plan_account_name, a.color AS plan_account_color
             FROM wishes w
             LEFT JOIN users u ON u.id = w.for_user_id
             LEFT JOIN accounts a ON a.id = w.plan_account_id';

    /** @param array{status?:string, occasion?:string, for_user_id?:?int, q?:string} $f */
    public function all(int $householdId, array $f = []): array
    {
        $where = ['w.household_id = ?'];
        $params = [$householdId];
        if (!empty($f['status']) && $f['status'] !== 'all') {
            $where[] = 'w.status = ?';
            $params[] = $f['status'];
        }
        if (!empty($f['occasion'])) {
            $where[] = 'w.occasion = ?';
            $params[] = $f['occasion'];
        }
        if (!empty($f['for_user_id'])) {
            $where[] = 'w.for_user_id = ?';
            $params[] = $f['for_user_id'];
        }
        if (!empty($f['q'])) {
            $where[] = '(w.title LIKE ? OR w.description LIKE ?)';
            $params[] = '%' . $f['q'] . '%';
            $params[] = '%' . $f['q'] . '%';
        }
        return $this->many(
            self::SELECT . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY w.status, w.wanted_by IS NULL, w.wanted_by, w.created_at DESC, w.id DESC',
            $params
        );
    }

    /** Offene Wünsche mit Sparplan (für Posten-Übersicht und Auslastung) */
    public function withPlans(int $householdId): array
    {
        return $this->many(
            self::SELECT . " WHERE w.household_id = ? AND w.status = 'open' AND w.plan_amount > 0 AND w.plan_start IS NOT NULL
             ORDER BY w.wanted_by IS NULL, w.wanted_by, w.id",
            [$householdId]
        );
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one(self::SELECT . ' WHERE w.id = ? AND w.household_id = ?', [$id, $householdId]);
    }

    /** Bisher verwendete Anlässe (für Vorschläge und Filter) */
    public function occasions(int $householdId): array
    {
        return array_column($this->many(
            "SELECT DISTINCT occasion FROM wishes WHERE household_id = ? AND occasion IS NOT NULL AND occasion <> '' ORDER BY occasion",
            [$householdId]
        ), 'occasion');
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('wishes', ['household_id' => $householdId] + $data);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        $this->updateWhere('wishes', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM wishes WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }
}
