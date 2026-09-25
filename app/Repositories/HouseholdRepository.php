<?php

declare(strict_types=1);

namespace App\Repositories;

final class HouseholdRepository extends Repository
{
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM households WHERE id = ?', [$id]);
    }

    public function create(string $name): int
    {
        return $this->insert('households', ['name' => $name]);
    }

    public function rename(int $id, string $name): void
    {
        $this->exec('UPDATE households SET name = ? WHERE id = ?', [$name, $id]);
    }
}
