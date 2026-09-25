<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\LoanRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\TransactionRepository;
use App\Services\ForecastService;
use App\Services\LoanCalculator;
use App\Services\RecurrenceService;
use App\Services\ReportService;

final class DashboardController extends Controller
{
    public function index(): void
    {
        $ids = Auth::accountIds('view');
        $accounts = (new AccountRepository())->withBalances($this->hid, $ids);
        if (!$accounts && Auth::isAdmin()) {
            $this->redirect('/accounts/new', 'Lege zuerst ein Konto an.', 'info');
        }

        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-t');
        $prevStart = date('Y-m-01', strtotime('first day of last month'));
        $prevEnd = date('Y-m-t', strtotime('last day of last month'));
        $report = new ReportService($this->hid, $ids);

        // Nächste Fixkosten (30 Tage)
        $upcoming = [];
        $until = date('Y-m-d', strtotime('+30 days'));
        foreach ((new RecurringRepository())->all($this->hid, $ids, true) as $tpl) {
            $from = date('Y-m-d', strtotime('+1 day'));
            if ($tpl['last_booked_date'] && $tpl['last_booked_date'] >= $from) {
                continue;
            }
            foreach (RecurrenceService::occurrences($tpl, $from, $until) as $d) {
                $upcoming[] = $tpl + ['date' => $d];
            }
        }
        usort($upcoming, fn ($a, $b) => $a['date'] <=> $b['date']);

        // Mini-Prognose 3 Monate
        $forecast = (new ForecastService($this->hid, $ids))->run(3, 6);
        $step = max(1, (int) floor(count($forecast['dates']) / 30));
        $mini = ['labels' => [], 'data' => []];
        foreach ($forecast['dates'] as $i => $d) {
            if ($i % $step === 0 || $i === count($forecast['dates']) - 1) {
                $mini['labels'][] = date_de($d, true);
                $mini['data'][] = $forecast['total'][$i];
            }
        }

        // Kredite
        $loanDebt = 0.0;
        foreach ((new LoanRepository())->all($this->hid) as $loan) {
            $loanDebt += (new LoanCalculator($loan, (new LoanRepository())->specials((int) $loan['id']), (new LoanRepository())->changes((int) $loan['id'])))->balanceAt(date('Y-m-d'));
        }

        $this->view('dashboard/index', [
            'title'      => 'Übersicht',
            'accounts'   => $accounts,
            'total'      => array_sum(array_map(fn ($a) => Money::toCents($a['balance']), $accounts)),
            'month'      => $report->totals($monthStart, $monthEnd),
            'prevMonth'  => $report->totals($prevStart, $prevEnd),
            'categories' => array_slice($report->byCategory($monthStart, $monthEnd), 0, 6),
            'recent'     => (new TransactionRepository())->search($this->hid, $ids, [], 8),
            'upcoming'   => array_slice($upcoming, 0, 6),
            'mini'       => $mini,
            'forecastMin' => $forecast['min'],
            'loanDebt'   => $loanDebt,
            'uncategorized' => (new TransactionRepository())->summary($this->hid, $ids, ['category_id' => 'none'])['count'],
            'scripts'    => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }
}
