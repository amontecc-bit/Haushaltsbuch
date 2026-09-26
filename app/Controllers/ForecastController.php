<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Core\Session;
use App\Repositories\AccountRepository;
use App\Repositories\ForecastScenarioRepository;
use App\Repositories\SettingsRepository;
use App\Services\ForecastService;

final class ForecastController extends Controller
{
    public function index(): void
    {
        $settings = (new SettingsRepository())->all($this->hid);
        $months = (int) $this->request->int('months', (int) $settings['forecast_months']);
        $months = in_array($months, [3, 6, 12, 24, 36], true) ? $months : 12;
        $avg = $this->avgMonths();
        $view = $this->request->str('view') === 'accounts' ? 'accounts' : 'total';

        // Szenario: ?scenario= (0 = nur berechneter Ø), sonst die zuletzt in dieser Sitzung gewählte
        $scenarioRepo = new ForecastScenarioRepository();
        $scenarioId = (int) $this->request->int('scenario', (int) Session::get('forecast_scenario', 0));
        $scenario = $scenarioId ? $scenarioRepo->find($scenarioId, $this->hid) : null;
        Session::set('forecast_scenario', $scenario ? (int) $scenario['id'] : 0);

        $result = (new ForecastService($this->hid, Auth::accountIds('view')))
            ->run($months, $avg, null, $scenario ? $scenarioRepo->values((int) $scenario['id']) : null);

        // Für das Diagramm auf höchstens ~370 Punkte ausdünnen (Monatsenden und Tiefpunkte bleiben erhalten)
        $n = count($result['dates']);
        $step = max(1, (int) ceil($n / 370));
        $idx = [];
        for ($i = 0; $i < $n; $i++) {
            $d = $result['dates'][$i];
            if ($i % $step === 0 || $i === $n - 1 || $d === $result['min']['date'] || substr($d, 8, 2) === date('t', strtotime($d))) {
                $idx[] = $i;
            }
        }
        $chart = [
            'labels'   => array_map(fn ($i) => date_de($result['dates'][$i]), $idx),
            'total'    => array_map(fn ($i) => $result['total'][$i], $idx),
            'baseline' => $result['baseline'] ? array_map(fn ($i) => $result['baseline'][$i], $idx) : null,
            'scenario' => $scenario['name'] ?? null,
            'accounts' => array_map(fn ($a) => [
                'name'  => $a['name'],
                'color' => $a['color'],
                'data'  => array_map(fn ($i) => $result['series'][(int) $a['id']][$i], $idx),
            ], $result['accounts']),
        ];

        // Monatsende-Tabelle (mit Vergleichswert „berechneter Ø“, falls ein Szenario abweicht)
        $monthEnds = [];
        foreach ($result['dates'] as $i => $d) {
            $monthEnds[substr($d, 0, 7)] = ['date' => $d, 'total' => $result['total'][$i], 'baseline' => $result['baseline'][$i] ?? null];
        }

        $this->view('forecast/index', [
            'title'     => 'Prognose der Kontostände',
            'months'    => $months,
            'avg'       => $avg,
            'viewMode'  => $view,
            'result'    => $result,
            'chart'     => $chart,
            'monthEnds' => $monthEnds,
            'scenario'  => $scenario,
            'scenarios' => $scenarioRepo->all($this->hid),
            // Zeitraum, aus dem der variable Ø stammt (letzte volle Monate)
            'avgFrom'   => date('Y-m-01', strtotime(date('Y-m-01') . " -$avg months")),
            'avgTo'     => date('Y-m-d', strtotime(date('Y-m-01') . ' -1 day')),
            'scripts'   => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }

    public function createScenario(): void
    {
        $this->denyChild();
        $repo = new ForecastScenarioRepository();
        $copy = ($id = $this->request->int('copy')) ? $repo->find($id, $this->hid) : null;
        $scenario = ['id' => null, 'name' => $copy ? 'Kopie von ' . $copy['name'] : '', 'note' => $copy['note'] ?? ''];
        $this->renderScenarioForm($scenario, $copy ? $repo->values((int) $copy['id']) : [], 'Neues Szenario');
    }

    public function storeScenario(): void
    {
        $this->denyChild();
        [$name, $note, $values, $error] = $this->validatedScenario();
        if ($error) {
            $this->renderScenarioForm(['id' => null, 'name' => $name, 'note' => $note], $values, 'Neues Szenario', $error);
            return;
        }
        $repo = new ForecastScenarioRepository();
        $id = $repo->transaction(function () use ($repo, $name, $note, $values) {
            $id = $repo->create($this->hid, $name, $note ?: null);
            $repo->saveValues($id, array_keys($this->forecastAccounts()), $this->toCents($values));
            return $id;
        });
        $this->redirect('/forecast?scenario=' . $id, 'Szenario gespeichert.');
    }

    public function editScenario(int $id): void
    {
        $this->denyChild();
        $repo = new ForecastScenarioRepository();
        $scenario = $repo->find($id, $this->hid) ?? $this->notFound();
        $this->renderScenarioForm($scenario, $repo->values($id), 'Szenario bearbeiten');
    }

    public function updateScenario(int $id): void
    {
        $this->denyChild();
        $repo = new ForecastScenarioRepository();
        $repo->find($id, $this->hid) ?? $this->notFound();
        [$name, $note, $values, $error] = $this->validatedScenario();
        if ($error) {
            $this->renderScenarioForm(['id' => $id, 'name' => $name, 'note' => $note], $values, 'Szenario bearbeiten', $error);
            return;
        }
        $repo->transaction(function () use ($repo, $id, $name, $note, $values) {
            $repo->update($id, $this->hid, $name, $note ?: null);
            $repo->saveValues($id, array_keys($this->forecastAccounts()), $this->toCents($values));
        });
        $this->redirect('/forecast?scenario=' . $id, 'Szenario gespeichert.');
    }

    public function deleteScenario(int $id): void
    {
        $this->denyChild();
        (new ForecastScenarioRepository())->delete($id, $this->hid);
        $this->redirect('/forecast?scenario=0', 'Szenario gelöscht.');
    }

    /**
     * Formularwerte: Name, Notiz, Handwerte je Konto (Euro; leeres Feld = berechneter Ø) und ggf. Fehlermeldung.
     * @return array{0:string, 1:string, 2:array<int,float>, 3:?string}
     */
    private function validatedScenario(): array
    {
        $r = $this->request;
        $name = trim(mb_substr($r->str('name'), 0, 100));
        $note = trim(mb_substr($r->str('note'), 0, 255));
        $input = $r->arr('amount');
        $values = [];
        $error = $name === '' ? 'Bitte einen Namen für das Szenario angeben.' : null;
        foreach ($this->forecastAccounts() as $id => $a) {
            $raw = trim((string) ($input[$id] ?? ''));
            if ($raw === '') {
                continue;
            }
            $cents = Money::parse($raw);
            if ($cents === null) {
                $error ??= "Betrag für „{$a['name']}“ ist ungültig.";
                continue;
            }
            $values[$id] = $cents / 100;
        }
        return [$name, $note, $values, $error];
    }

    /** @param array<int,float> $values @return array<int,int> */
    private function toCents(array $values): array
    {
        return array_map(fn ($v) => (int) round($v * 100), $values);
    }

    /** Sichtbare Konten, die in die Prognose eingehen (id => Konto) */
    private function forecastAccounts(): array
    {
        $out = [];
        foreach ((new AccountRepository())->withBalances($this->hid, Auth::accountIds('view')) as $a) {
            if ((int) $a['include_in_forecast'] === 1) {
                $out[(int) $a['id']] = $a;
            }
        }
        return $out;
    }

    private function avgMonths(): int
    {
        $settings = (new SettingsRepository())->all($this->hid);
        return max(0, min(24, (int) $this->request->int('avg', (int) $settings['forecast_avg_months'])));
    }

    private function renderScenarioForm(array $scenario, array $values, string $title, ?string $error = null): void
    {
        $accounts = $this->forecastAccounts();
        $avg = $this->avgMonths();
        $computed = $avg ? (new ForecastService($this->hid, Auth::accountIds('view')))->variableAverages(array_keys($accounts), $avg, date('Y-m-d')) : [];
        $this->view('forecast/scenario_form', [
            'title'    => $title,
            'back'     => '/forecast',
            'scenario' => $scenario,
            'values'   => $values,
            'accounts' => $accounts,
            'computed' => $computed,
            'avg'      => $avg,
            'error'    => $error,
        ]);
    }

    private function denyChild(): void
    {
        if (Auth::isChild()) {
            $this->redirect('/forecast', 'Keine Berechtigung.', 'danger');
        }
    }
}
