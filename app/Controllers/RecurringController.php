<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringRepository;
use App\Services\RecurrenceService;

final class RecurringController extends Controller
{
    public function index(): void
    {
        $rows = (new RecurringRepository())->all($this->hid, Auth::accountIds('view'));
        $income = 0.0;
        $expense = 0.0;
        foreach ($rows as &$r) {
            $r['next'] = $r['active'] ? RecurrenceService::nextOccurrence($r, date('Y-m-d', strtotime('+1 day'))) : null;
            if ($r['active'] && !$r['to_account_id'] && (!$r['end_date'] || $r['end_date'] >= date('Y-m-d'))) {
                $m = RecurrenceService::monthlyAmount($r['amount'], $r['interval']);
                $m > 0 ? $income += $m : $expense += $m;
            }
        }
        unset($r);
        $actions = '<a href="' . e(url('/recurring/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Fixkosten / Dauerauftrag</a>';
        $this->view('recurring/index', ['title' => 'Fixkosten & Daueraufträge', 'rows' => $rows, 'income' => $income, 'expense' => $expense, 'actions' => $actions]);
    }

    public function create(): void
    {
        $tpl = [
            'id' => null, 'kind' => 'expense', 'account_id' => null, 'to_account_id' => null, 'category_id' => null, 'amount' => '',
            'payee' => '', 'purpose' => '', 'interval' => 'monthly', 'start_date' => date('Y-m-d'), 'end_date' => '',
            'auto_book' => 1, 'active' => 1, 'last_booked_date' => null,
        ];
        $this->renderForm($tpl, 'Neue Fixkosten');
    }

    public function store(): void
    {
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($this->formFromData($data), 'Neue Fixkosten', $error);
            return;
        }
        $repo = new RecurringRepository();
        $data['created_by'] = Auth::id();
        // Vergangene Termine nur buchen, wenn gewünscht
        if (!$this->request->bool('book_past')) {
            $data['last_booked_date'] = date('Y-m-d', strtotime('-1 day'));
            if ($data['start_date'] > $data['last_booked_date']) {
                $data['last_booked_date'] = null;
            }
        }
        $repo->create($this->hid, $data);
        $n = RecurrenceService::materializeDue($this->hid);
        $this->redirect('/recurring', 'Gespeichert' . ($n ? " – $n fällige Buchungen wurden angelegt." : '.'));
    }

    public function edit(int $id): void
    {
        $tpl = (new RecurringRepository())->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'view');
        $this->renderForm($this->formFromData($tpl), 'Fixkosten bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new RecurringRepository();
        $tpl = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'book');
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($this->formFromData(['id' => $id] + $data), 'Fixkosten bearbeiten', $error);
            return;
        }
        // Wird der Start nach vorn verlegt, keine alten Termine nachbuchen
        if ($tpl['last_booked_date'] && $data['start_date'] > $tpl['last_booked_date']) {
            $data['last_booked_date'] = null;
        }
        $repo->update($id, $this->hid, $data);
        RecurrenceService::materializeDue($this->hid);
        $this->redirect('/recurring', 'Gespeichert. Änderungen gelten für künftige Buchungen.');
    }

    public function delete(int $id): void
    {
        $repo = new RecurringRepository();
        $tpl = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tpl['account_id'], 'book');
        $repo->delete($id, $this->hid);
        $this->redirect('/recurring', 'Gelöscht. Bereits erzeugte Buchungen bleiben erhalten.');
    }

    private function validated(): array
    {
        $r = $this->request;
        $kind = in_array($r->str('kind'), ['expense', 'income', 'transfer'], true) ? $r->str('kind') : 'expense';
        $cents = abs((int) Money::parse($r->str('amount')));
        $interval = array_key_exists($r->str('interval'), RecurrenceService::STEPS) ? $r->str('interval') : 'monthly';
        $start = valid_date($r->str('start_date'), date('Y-m-d'));
        $data = [
            'account_id'    => (int) $r->int('account_id'),
            'to_account_id' => $kind === 'transfer' ? (int) $r->int('to_account_id') : null,
            'category_id'   => $kind === 'transfer' ? null : (new CategoryRepository())->validId($r->int('category_id'), $this->hid),
            'amount'        => Money::toDecimal($kind === 'income' ? $cents : -$cents),
            'payee'         => mb_substr($r->str('payee'), 0, 190) ?: null,
            'purpose'       => mb_substr($r->str('purpose'), 0, 255) ?: null,
            'interval'      => $interval,
            'day_of_month'  => (int) substr($start, 8, 2),
            'start_date'    => $start,
            'end_date'      => valid_date($r->str('end_date')),
            'auto_book'     => $r->bool('auto_book') ? 1 : 0,
            'active'        => $r->bool('active') ? 1 : 0,
        ];
        $error = null;
        if ($cents === 0) {
            $error = 'Bitte einen Betrag eingeben.';
        } elseif (!Auth::can($data['account_id'], 'book')) {
            $error = 'Auf dieses Konto darfst du nicht buchen.';
        } elseif ($kind === 'transfer' && (!Auth::can($data['to_account_id'], 'book') || $data['to_account_id'] === $data['account_id'])) {
            $error = 'Bitte ein anderes Zielkonto wählen.';
        } elseif ($data['end_date'] && $data['end_date'] < $start) {
            $error = 'Das Enddatum liegt vor dem Start.';
        }
        return [$data, $error];
    }

    private function formFromData(array $d): array
    {
        $cents = Money::toCents($d['amount']);
        return [
            'id' => $d['id'] ?? null,
            'kind' => $d['to_account_id'] ? 'transfer' : ($cents > 0 ? 'income' : 'expense'),
            'amount' => $cents ? money_input(abs($cents) / 100) : '',
        ] + $d;
    }

    private function renderForm(array $tpl, string $title, ?string $error = null): void
    {
        $accounts = (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
        if (!$accounts) {
            $this->redirect('/accounts', 'Es gibt noch kein Konto, auf das du buchen darfst.', 'warning');
        }
        $this->view('recurring/form', [
            'title' => $title, 'back' => '/recurring', 'tpl' => $tpl, 'error' => $error,
            'accounts' => $accounts, 'categories' => (new CategoryRepository())->all($this->hid),
        ]);
    }
}
