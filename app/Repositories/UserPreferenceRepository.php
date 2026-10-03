<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserPreferenceRepository extends Repository
{
    /** Vorzugskonten: Schlüssel => Beschriftung in den Einstellungen */
    public const ACCOUNTS = [
        'account_purchase' => 'Einkauf erfassen – bezahlt von',
        'account_import'   => 'Kontoauszug importieren – auf Konto',
        'account_booking'  => 'Neue Buchung – Konto',
        'account_transfer' => 'Umbuchung – Zielkonto',
    ];

    public function all(int $userId): array
    {
        $out = [];
        foreach ($this->many('SELECT `key`, value FROM user_preferences WHERE user_id = ?', [$userId]) as $r) {
            $out[$r['key']] = (string) $r['value'];
        }
        return $out;
    }

    public function get(int $userId, string $key): ?string
    {
        $v = $this->value('SELECT value FROM user_preferences WHERE user_id = ? AND `key` = ?', [$userId, $key]);
        return $v !== null ? (string) $v : null;
    }

    public function set(int $userId, string $key, ?string $value): void
    {
        if ($value === null || $value === '') {
            $this->exec('DELETE FROM user_preferences WHERE user_id = ? AND `key` = ?', [$userId, $key]);
            return;
        }
        $this->exec(
            'INSERT INTO user_preferences (user_id, `key`, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$userId, $key, $value]
        );
    }

    /**
     * Vorzugskonto des Benutzers, falls er es (noch) verwenden darf.
     * @param int[] $allowedIds
     */
    public function account(int $userId, string $key, array $allowedIds): ?int
    {
        $id = (int) $this->get($userId, $key);
        return $id && in_array($id, $allowedIds, true) ? $id : null;
    }
}
