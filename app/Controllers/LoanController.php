<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\LoanRepository;
use App\Repositories\RecurringRepository;
use App\Services\CategorizationService;
use App\Services\LoanCalculator;
use App\Services\RecurrenceService;

final class LoanController extends Controller
{
    public function index(): void
    {
        $repo = new LoanRepository();
        $loans = [];
        foreach ($repo->all($this->hid) as $l) {
            $calc = $this->calculator($l);
            $loans[] = $l + ['summary' => $calc->summary()];
        }
        $actions = Auth::isChild() ? '' : '<a class="btn btn-primary" href="' . e(url('/loans/new')) . '"><i class="bi bi-plus-lg"></i> Kredit</a>';
        $this->view('loans/index', ['title' => 'Kredite', 'loans' => $loans, 'actions' => $actions]);
    }

    public function create(): void
    {
        $this->denyChild();
        $loan = ['id' => null, 'name' => '', 'lender' => '', 'contract_number' => '', 'principal' => '', 'payout_date' => date('Y-m-d'),
            'interest_rate' => '', 'loan_type' => 'annuity', 'monthly_payment' => '', 'initial_repayment_rate' => '',
            'first_payment_date' => date('Y-m-d', strtotime('first day of next month')), 'fixed_until' => '', 'account_id' => null, 'note' => ''];
        $this->renderForm($loan, 'Neuer Kredit');
    }

    public function store(): void
    {
        $this->denyChild();
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($data + ['id' => null], 'Neuer Kredit', $error);
            return;
        }
        $id = (new LoanRepository())->create($this->hid, $data);
        $this->redirect("/loans/$id", 'Kredit angelegt.');
    }

    public function show(int $id): void
    {
        $repo = new LoanRepository();
        $loan = $repo->find($id, $this->hid) ?? $this->notFound();
        $calc = $this->calculator($loan);
        $asOf = valid_date($this->request->str('date'), date('Y-m-d'));
        $this->view('loans/show', [
            'title'     => $loan['name'],
            'back'      => '/loans',
            'loan'      => $loan,
            'asOf'      => $asOf,
            'summary'   => $calc->summary($asOf),
            'today'     => $calc->summary(),
            'schedule'  => $calc->schedule(),
            'yearly'    => $calc->yearly(),
            'specials'  => $repo->specials($id),
            'changes'   => $repo->changes($id),
            'recurring' => (new RecurringRepository())->findByLoan($id),
            'canEdit'   => !Auth::isChild(),
            'actions'   => Auth::isChild() ? '' : '<a class="btn btn-outline-primary" href="' . e(url("/loans/$id/edit")) . '"><i class="bi bi-pencil"></i> Bearbeiten</a>',
            'scripts'   => ['vendor/chartjs/chart.umd.min.js'],
        ]);
    }

    public function edit(int $id): void
    {
        $this->denyChild();
        $loan = (new LoanRepository())->find($id, $this->hid) ?? $this->notFound();
        $loan['principal'] = money_input($loan['principal']);
        $loan['monthly_payment'] = money_input($loan['monthly_payment']);
        $loan['interest_rate'] = rtrim(rtrim(number_format((float) $loan['interest_rate'], 3, ',', ''), '0'), ',');
        $loan['initial_repayment_rate'] = $loan['initial_repayment_rate'] !== null ? rtrim(rtrim(number_format((float) $loan['initial_repayment_rate'], 3, ',', ''), '0'), ',') : '';
        $this->renderForm($loan, 'Kredit bearbeiten');
    }

    public function update(int $id): void
    {
        $this->denyChild();
        $repo = new LoanRepository();
        $repo->find($id, $this->hid) ?? $this->notFound();
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($data + ['id' => $id], 'Kredit bearbeiten', $error);
            return;
        }
        $repo->update($id, $this->hid, $data);
        $this->redirect("/loans/$id", 'Gespeichert.');
    }

    public function delete(int $id): void
    {
        $this->denyChild();
        (new LoanRepository())->delete($id, $this->hid);
        $this->redirect('/loans', 'Kredit gelöscht.');
    }

    public function addSpecial(int $id): void
    {
        $this->denyChild();
        $repo = new LoanRepository();
        $repo->find($id, $this->hid) ?? $this->notFound();
        $cents = Money::parse($this->request->str('amount'));
        $date = valid_date($this->request->str('payment_date'));
        if (!$cents || !$date) {
            $this->redirect("/loans/$id", 'Bitte Datum und Betrag der Sondertilgung angeben.', 'danger');
        }
        $repo->addSpecial($id, $date, Money::toDecimal(abs($cents)), mb_substr($this->request->str('note'), 0, 255) ?: null);
        $this->redirect("/loans/$id", 'Sondertilgung erfasst.');
    }

    public function deleteSpecial(int $id, int $sid): void
    {
        $this->denyChild();
        (new LoanRepository())->find($id, $this->hid) ?? $this->notFound();
        (new LoanRepository())->deleteSpecial($id, $sid);
        $this->redirect("/loans/$id", 'Sondertilgung entfernt.');
    }

    public function addChange(int $id): void
    {
        $this->denyChild();
        $repo = new LoanRepository();
        $repo->find($id, $this->hid) ?? $this->notFound();
        $date = valid_date($this->request->str('valid_from'));
        $rate = $this->request->str('interest_rate');
        $payment = Money::parse($this->request->str('monthly_payment'));
        $rate = $rate !== '' ? (string) (float) str_replace(',', '.', $rate) : null;
        if (!$date || ($rate === null && !$payment)) {
            $this->redirect("/loans/$id", 'Bitte Datum und neuen Zins und/oder neue Rate angeben.', 'danger');
        }
        $repo->addChange($id, $date, $rate, $payment ? Money::toDecimal(abs($payment)) : null, mb_substr($this->request->str('note'), 0, 255) ?: null);
        $this->redirect("/loans/$id", 'Änderung erfasst.');
    }

    public function deleteChange(int $id, int $cid): void
    {
        $this->denyChild();
        (new LoanRepository())->find($id, $this->hid) ?? $this->notFound();
        (new LoanRepository())->deleteChange($id, $cid);
        $this->redirect("/loans/$id", 'Änderung entfernt.');
    }

    /** Monatliche Rate als wiederkehrende Buchung auf dem verknüpften Konto anlegen (für Prognose/Buchung) */
    public function createRecurring(int $id): void
    {
        $this->denyChild();
        $loan = (new LoanRepository())->find($id, $this->hid) ?? $this->notFound();
        if (!$loan['account_id'] || !Auth::can((int) $loan['account_id'], 'book')) {
            $this->redirect("/loans/$id", 'Bitte zuerst ein Konto hinterlegen, von dem die Rate abgebucht wird.', 'danger');
        }
        $recRepo = new RecurringRepository();
        if ($recRepo->findByLoan($id)) {
            $this->redirect("/loans/$id", 'Für diesen Kredit gibt es bereits eine wiederkehrende Buchung.', 'warning');
        }
        $calc = $this->calculator($loan);
        $summary = $calc->summary();
        $next = $summary['next_payment'];
        if (!$next) {
            $this->redirect("/loans/$id", 'Der Kredit ist bereits vollständig getilgt.', 'info');
        }
        $end = $summary['end_date'];
        $catId = (new CategorizationService($this->hid))->categoryIdByName('Kreditrate');
        $recRepo->create($this->hid, [
            'account_id'   => (int) $loan['account_id'],
            'category_id'  => $catId,
            'amount'       => Money::toDecimal(-$summary['current_payment']),
            'payee'        => $loan['lender'] ?: $loan['name'],
            'purpose'      => 'Kreditrate ' . $loan['name'],
            'interval'     => 'monthly',
            'day_of_month' => (int) substr($loan['first_payment_date'], 8, 2),
            'start_date'   => $next['date'],
            'end_date'     => $end,
            'auto_book'    => $this->request->bool('auto_book') ? 1 : 0,
            'loan_id'      => $id,
            'created_by'   => Auth::id(),
        ]);
        RecurrenceService::materializeDue($this->hid);
        $this->redirect("/loans/$id", 'Die Rate ist jetzt als wiederkehrende Buchung angelegt und fließt in die Prognose ein.');
    }

    private function calculator(array $loan): LoanCalculator
    {
        $repo = new LoanRepository();
        return new LoanCalculator($loan, $repo->specials((int) $loan['id']), $repo->changes((int) $loan['id']));
    }

    private function validated(): array
    {
        $r = $this->request;
        $pct = fn (string $v) => $v === '' ? null : (float) str_replace(',', '.', $v);
        $type = $r->str('loan_type') === 'fixed_principal' ? 'fixed_principal' : 'annuity';
        $principal = Money::parse($r->str('principal'));
        $payment = Money::parse($r->str('monthly_payment'));
        $accountId = $r->int('account_id') ?: null;
        $data = [
            'name'                   => mb_substr($r->str('name'), 0, 120),
            'lender'                 => mb_substr($r->str('lender'), 0, 120) ?: null,
            'contract_number'        => mb_substr($r->str('contract_number'), 0, 60) ?: null,
            'principal'              => Money::toDecimal(abs((int) $principal)),
            'payout_date'            => valid_date($r->str('payout_date'), date('Y-m-d')),
            'interest_rate'          => (string) ($pct($r->str('interest_rate')) ?? 0),
            'loan_type'              => $type,
            'monthly_payment'        => $payment ? Money::toDecimal(abs($payment)) : null,
            'initial_repayment_rate' => $pct($r->str('initial_repayment_rate')) !== null ? (string) $pct($r->str('initial_repayment_rate')) : null,
            'first_payment_date'     => valid_date($r->str('first_payment_date'), date('Y-m-d')),
            'fixed_until'            => valid_date($r->str('fixed_until')),
            'account_id'             => $accountId && Auth::can($accountId, 'view') ? $accountId : null,
            'note'                   => mb_substr($r->str('note'), 0, 2000) ?: null,
        ];
        $error = null;
        if ($data['name'] === '') {
            $error = 'Bitte einen Namen angeben.';
        } elseif (!$principal) {
            $error = 'Bitte den Kreditbetrag angeben.';
        } elseif (!$payment && !$data['initial_repayment_rate']) {
            $error = $type === 'annuity' ? 'Bitte Monatsrate oder anfängliche Tilgung angeben.' : 'Bitte die monatliche Tilgung oder den Tilgungssatz angeben.';
        } elseif ($data['first_payment_date'] < $data['payout_date']) {
            $error = 'Die erste Rate liegt vor der Auszahlung.';
        } elseif ($type === 'annuity' && $payment && abs($payment) <= (int) round(abs((int) $principal) * (float) $data['interest_rate'] / 100 / 12)) {
            $error = 'Die Rate deckt nicht einmal die Zinsen – der Kredit würde nie getilgt.';
        }
        return [$data, $error];
    }

    private function denyChild(): void
    {
        if (Auth::isChild()) {
            $this->redirect('/loans', 'Keine Berechtigung.', 'danger');
        }
    }

    private function renderForm(array $loan, string $title, ?string $error = null): void
    {
        $this->view('loans/form', [
            'title' => $title, 'back' => $loan['id'] ? "/loans/{$loan['id']}" : '/loans', 'loan' => $loan, 'error' => $error,
            'accounts' => (new AccountRepository())->options($this->hid, Auth::accountIds('view')),
        ]);
    }
}
