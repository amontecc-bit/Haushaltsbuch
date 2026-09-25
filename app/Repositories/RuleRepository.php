<?php

declare(strict_types=1);

namespace App\Repositories;

final class RuleRepository extends Repository
{
    public function all(int $householdId): array
    {
        return $this->many(
            'SELECT r.*, c.name AS category_name, c.icon AS category_icon, c.color AS category_color, p.name AS category_parent
             FROM category_rules r
             JOIN categories c ON c.id = r.category_id
             LEFT JOIN categories p ON p.id = c.parent_id
             WHERE r.household_id = ?
             ORDER BY r.target, r.priority, r.id',
            [$householdId]
        );
    }

    public function create(int $householdId, array $data): int
    {
        return $this->insert('category_rules', ['household_id' => $householdId] + $data);
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM category_rules WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function exists(int $householdId, string $target, string $field, string $operator, string $value): bool
    {
        return (bool) $this->value(
            'SELECT 1 FROM category_rules WHERE household_id = ? AND target = ? AND field = ? AND operator = ? AND value = ?',
            [$householdId, $target, $field, $operator, $value]
        );
    }
}
