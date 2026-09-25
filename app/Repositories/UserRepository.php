<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function findInHousehold(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM users WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    public function findByEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM users WHERE email = ?', [mb_strtolower(trim($email))]);
    }

    public function all(int $householdId): array
    {
        return $this->many("SELECT * FROM users WHERE household_id = ? ORDER BY FIELD(role, 'admin', 'member', 'child'), name", [$householdId]);
    }

    public function count(): int
    {
        return (int) $this->value('SELECT COUNT(*) FROM users');
    }

    public function create(int $householdId, string $name, string $email, string $password, string $role): int
    {
        return $this->insert('users', [
            'household_id'  => $householdId,
            'name'          => $name,
            'email'         => mb_strtolower(trim($email)),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => $role,
        ]);
    }

    public function update(int $id, int $householdId, array $data): void
    {
        if (isset($data['email'])) {
            $data['email'] = mb_strtolower(trim($data['email']));
        }
        $this->updateWhere('users', $data, ['id' => $id, 'household_id' => $householdId]);
    }

    public function updatePassword(int $id, string $password): void
    {
        $this->exec('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function registerFailedLogin(int $id, int $failed, ?string $lockedUntil): void
    {
        $this->exec('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$failed, $lockedUntil, $id]);
    }

    public function registerLogin(int $id): void
    {
        $this->exec('UPDATE users SET failed_logins = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?', [$id]);
    }

    public function adminCount(int $householdId): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM users WHERE household_id = ? AND role = 'admin' AND active = 1", [$householdId]);
    }
}
