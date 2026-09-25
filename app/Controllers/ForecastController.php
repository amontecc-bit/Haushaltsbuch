<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Repositories\SettingsRepository;
use App\Services\ForecastService;

final class ForecastController extends Controller
{
    public function index(): void
    {
        $settings = (new SettingsRepository())->all($this->hid);
        $months = (int) $this->request->int('months', (int) $settings['forecast_months']);
        $months = in_array($months, [3, 6, 12, 24, 36], true) ? $months : 12;
        $avg = max(0, min(24, (int) $this->request->int('avg', (int) $settings['forecast_avg_months'])));
        $view = $this->request->str('view') === 'accounts' ? 'accounts' : 'total';

        $result = (new ForecastService($this->hid, Auth::accountIds('view')))->run($months, $avg);

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
            'labels' => array_map(fn ($i) => date_de($result['dates'][$i]), $idx),
            'total'  => array_map(fn ($i) => $result['total'][$i], $idx),
            'accounts' => array_map(fn ($a) => [
                'name'  => $a['name'],
                'color' => $a['color'],
                'data'  => array_map(fn ($i) => $result['series'][(int) $a['id']][$i], $idx),
            ], $result['accounts']),
        ];

        // Monatsende-Tabelle
        $monthEnds = [];
        foreach ($result['dates'] as $i => $d) {
            $monthEnds[substr($d, 0, 7)] = ['date' => $d, 'total' => $result['total'][$i]];
        }

        $this->view('forecast/index', [
            'title'     => 'Prognose der Kontostände',
            'months'    => $months,
            'avg'       => $avg,
            'viewMode'  => $view,
            'result'    => $result,
            'chart'     => $chart,
            'monthEnds' => $monthEnds,
            'scripts'   => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }
}
