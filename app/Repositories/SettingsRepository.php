<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    public const DEFAULTS = [
        'ocr_mode'           => 'local',   // local | ai
        'ai_api_key'         => '',
        'ai_model'           => '',
        'forecast_months'    => '12',
        'forecast_avg_months' => '6',
        'household_name'     => '',
    ];

    public function all(int $householdId): array
    {
        $rows = $this->many('SELECT `key`, value FROM settings WHERE household_id = ?', [$householdId]);
        $out = self::DEFAULTS;
        foreach ($rows as $r) {
            $out[$r['key']] = (string) $r['value'];
        }
        return $out;
    }

    public function get(int $householdId, string $key, ?string $default = null): ?string
    {
        $v = $this->value('SELECT value FROM settings WHERE household_id = ? AND `key` = ?', [$householdId, $key]);
        return $v !== null ? (string) $v : ($default ?? self::DEFAULTS[$key] ?? null);
    }

    public function set(int $householdId, string $key, ?string $value): void
    {
        $this->exec(
            'INSERT INTO settings (household_id, `key`, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)',
            [$householdId, $key, $value]
        );
    }
}
