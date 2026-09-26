<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Money;

final class ForecastScenarioRepository extends Repository
{
    public function all(int $householdId): array
    {
        return $this->many('SELECT * FROM forecast_scenarios WHERE household_id = ? ORDER BY name', [$householdId]);
    }

    public function find(int $id, int $householdId): ?array
    {
        return $this->one('SELECT * FROM forecast_scenarios WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }

    /**
     * Von Hand festgelegte Monatswerte (Euro) je Konto; fehlende Konten = berechneter Ø.
     * @return array<int,float>
     */
    public function values(int $scenarioId): array
    {
        $out = [];
        foreach ($this->many('SELECT account_id, monthly_amount FROM forecast_scenario_values WHERE scenario_id = ?', [$scenarioId]) as $r) {
            $out[(int) $r['account_id']] = Money::toCents($r['monthly_amount']) / 100;
        }
        return $out;
    }

    public function create(int $householdId, string $name, ?string $note): int
    {
        return $this->insert('forecast_scenarios', ['household_id' => $householdId, 'name' => $name, 'note' => $note]);
    }

    public function update(int $id, int $householdId, string $name, ?string $note): void
    {
        $this->updateWhere('forecast_scenarios', ['name' => $name, 'note' => $note], ['id' => $id, 'household_id' => $householdId]);
    }

    /**
     * Werte der angegebenen Konten ersetzen (andere Konten – z. B. nicht sichtbare – bleiben unberührt).
     * @param int[] $accountIds bearbeitete Konten
     * @param array<int,int> $cents Konto => Cent; fehlend = berechneter Ø
     */
    public function saveValues(int $scenarioId, array $accountIds, array $cents): void
    {
        if (!$accountIds) {
            return;
        }
        $this->exec(
            'DELETE FROM forecast_scenario_values WHERE scenario_id = ? AND account_id IN (' . self::in($accountIds) . ')',
            [$scenarioId, ...$accountIds]
        );
        foreach ($cents as $acc => $c) {
            $this->insert('forecast_scenario_values', ['scenario_id' => $scenarioId, 'account_id' => $acc, 'monthly_amount' => Money::toDecimal($c)]);
        }
    }

    public function delete(int $id, int $householdId): void
    {
        $this->exec('DELETE FROM forecast_scenarios WHERE id = ? AND household_id = ?', [$id, $householdId]);
    }
}
