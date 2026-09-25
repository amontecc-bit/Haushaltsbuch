<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\CategoryRepository;
use App\Repositories\RecurringRepository;
use App\Repositories\RuleRepository;
use App\Repositories\TransactionRepository;
use App\Repositories\UserRepository;
use App\Services\CategorizationService;
use App\Services\RecurrenceService;
use App\Services\TransactionService;

final class TransactionController extends Controller
{
    private const PER_PAGE = 50;

    public function index(): void
    {
        $r = $this->request;
        $filters = [
            'account_id'  => $r->int('account_id'),
            'category_id' => $r->str('category_id') === 'none' ? 'none' : $r->int('category_id'),
            'from'        => valid_date($r->str('from')),
            'to'          => valid_date($r->str('to')),
            'q'           => mb_substr($r->str('q'), 0, 100),
            'type'        => in_array($r->str('type'), ['income', 'expense', 'transfer'], true) ? $r->str('type') : '',
            'user_id'     => $r->int('user_id'),
        ];
        $page = max(1, (int) $r->int('page', 1));
        $repo = new TransactionRepository();
        $ids = Auth::accountIds('view');
        $rows = $repo->search($this->hid, $ids, $filters, self::PER_PAGE + 1, ($page - 1) * self::PER_PAGE);
        $hasMore = count($rows) > self::PER_PAGE;
        $rows = array_slice($rows, 0, self::PER_PAGE);

        $actions = '<a href="' . e(url('/transactions/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Buchung</a>'
            . '<a href="' . e(url('/import')) . '" class="btn btn-outline-secondary"><i class="bi bi-upload"></i> CSV-Import</a>';

        $this->view('transactions/index', [
            'title'      => 'Buchungen',
            'actions'    => $actions,
            'rows'       => $rows,
            'filters'    => $filters,
            'page'       => $page,
            'hasMore'    => $hasMore,
            'summary'    => $repo->summary($this->hid, $ids, $filters),
            'accounts'   => (new AccountRepository())->options($this->hid, $ids),
            'categories' => (new CategoryRepository())->all($this->hid),
            'users'      => (new UserRepository())->all($this->hid),
            'canBook'    => Auth::accountIds('book'),
        ]);
    }

    public function create(): void
    {
        $type = in_array($this->request->str('type'), ['expense', 'income', 'transfer'], true) ? $this->request->str('type') : 'expense';
        $accounts = (new AccountRepository())->options($this->hid, Auth::accountIds('book'));
        if (!$accounts) {
            $this->redirect('/accounts', 'Es gibt noch kein Konto, auf das du buchen darfst.', 'warning');
        }
        $tx = [
            'id' => null, 'kind' => $type, 'amount' => '', 'account_id' => $this->request->int('account_id') ?? $accounts[0]['id'],
            'to_account_id' => null, 'category_id' => null, 'booking_date' => date('Y-m-d'), 'payee' => '', 'purpose' => '', 'note' => '',
        ];
        $this->renderForm($tx, 'Neue Buchung');
    }

    public function store(): void
    {
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($data, 'Neue Buchung', $error);
            return;
        }
        $service = new TransactionService();
        $cents = $data['cents'];
        $common = [
            'booking_date' => $data['booking_date'],
            'payee'        => $data['payee'] ?: null,
            'purpose'      => $data['purpose'] ?: null,
            'note'         => $data['note'] ?: null,
            'created_by'   => Auth::id(),
        ];

        if ($this->request->bool('repeat')) {
            $interval = $this->request->str('interval');
            $interval = array_key_exists($interval, RecurrenceService::STEPS) ? $interval : 'monthly';
            (new RecurringRepository())->create($this->hid, [
                'account_id'    => $data['account_id'],
                'to_account_id' => $data['kind'] === 'transfer' ? $data['to_account_id'] : null,
                'category_id'   => $data['kind'] === 'transfer' ? null : $data['category_id'],
                'amount'        => Money::toDecimal($data['kind'] === 'income' ? $cents : -$cents),
                'payee'         => $common['payee'],
                'purpose'       => $common['purpose'],
                'interval'      => $interval,
                'day_of_month'  => (int) substr($data['booking_date'], 8, 2),
                'start_date'    => $data['booking_date'],
                'end_date'      => valid_date($this->request->str('end_date')),
                'created_by'    => Auth::id(),
            ]);
            $n = RecurrenceService::materializeDue($this->hid);
            $this->maybeCreateRule($data);
            $this->redirect('/recurring', 'Wiederkehrende Buchung angelegt' . ($n ? " ($n bereits fällig und gebucht)." : '.'));
        }

        if ($data['kind'] === 'transfer') {
            $service->createTransfer($this->hid, $data['account_id'], $data['to_account_id'], $cents, $data['booking_date'], $common);
        } else {
            $service->create($this->hid, $common + [
                'account_id'  => $data['account_id'],
                'amount'      => Money::toDecimal($data['kind'] === 'income' ? $cents : -$cents),
                'category_id' => $data['category_id'],
            ]);
        }
        $this->maybeCreateRule($data);

        if ($this->request->bool('another')) {
            $this->redirect('/transactions/new?type=' . $data['kind'] . '&account_id=' . $data['account_id'], 'Gespeichert. Nächste Buchung:');
        }
        $this->redirect('/transactions', 'Buchung gespeichert.');
    }

    public function edit(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'view');
        $partner = $repo->transferPartner($tx);
        $cents = Money::toCents($tx['amount']);
        $form = [
            'id' => $id, 'amount' => money_input(abs($cents) / 100), 'booking_date' => $tx['booking_date'],
            'payee' => $tx['payee'], 'purpose' => $tx['purpose'], 'note' => $tx['note'], 'category_id' => $tx['category_id'],
            'source' => $tx['source'], 'recurring_id' => $tx['recurring_id'],
        ];
        if ($partner) {
            $out = $cents < 0 ? $tx : $partner;
            $in = $cents < 0 ? $partner : $tx;
            $form += ['kind' => 'transfer', 'account_id' => $out['account_id'], 'to_account_id' => $in['account_id']];
        } else {
            $form += ['kind' => $cents >= 0 ? 'income' : 'expense', 'account_id' => $tx['account_id'], 'to_account_id' => null];
        }
        $this->renderForm($form, 'Buchung bearbeiten');
    }

    public function update(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'book');
        [$data, $error] = $this->validated();
        $data['id'] = $id;
        if ($error) {
            $this->renderForm($data, 'Buchung bearbeiten', $error);
            return;
        }
        $common = [
            'booking_date' => $data['booking_date'],
            'payee'        => $data['payee'] ?: null,
            'purpose'      => $data['purpose'] ?: null,
            'note'         => $data['note'] ?: null,
        ];
        $repo->transaction(function () use ($repo, $tx, $data, $common, $id) {
            $wasTransfer = (bool) $tx['transfer_group'];
            if ($wasTransfer || $data['kind'] === 'transfer') {
                // Umbuchungen werden neu aufgebaut (beide Seiten)
                if ($wasTransfer) {
                    $repo->deleteTransferGroup($tx['transfer_group'], $this->hid);
                } else {
                    $repo->delete($id, $this->hid);
                }
                $extra = $common + ['created_by' => $tx['created_by'], 'source' => $tx['source'], 'import_hash' => $tx['import_hash']];
                if ($data['kind'] === 'transfer') {
                    (new TransactionService($repo))->createTransfer($this->hid, $data['account_id'], $data['to_account_id'], $data['cents'], $data['booking_date'], $extra);
                } else {
                    $repo->create($this->hid, $extra + [
                        'account_id' => $data['account_id'], 'category_id' => $data['category_id'],
                        'amount'     => Money::toDecimal($data['kind'] === 'income' ? $data['cents'] : -$data['cents']),
                    ]);
                }
                return;
            }
            $repo->update($id, $this->hid, $common + [
                'account_id'  => $data['account_id'],
                'category_id' => $data['category_id'],
                'amount'      => Money::toDecimal($data['kind'] === 'income' ? $data['cents'] : -$data['cents']),
            ]);
        });
        $this->maybeCreateRule($data);
        $return = $this->request->str('return');
        $this->redirect(str_starts_with($return, '/transactions') ? $return : '/transactions', 'Buchung gespeichert.');
    }

    public function delete(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid) ?? $this->notFound();
        Auth::authorize((int) $tx['account_id'], 'book');
        if ($tx['transfer_group']) {
            $repo->deleteTransferGroup($tx['transfer_group'], $this->hid);
        } else {
            $repo->delete($id, $this->hid);
        }
        $this->redirect('/transactions', 'Buchung gelöscht.');
    }

    /** AJAX: Kategorie einer Buchung setzen */
    public function setCategory(int $id): void
    {
        $repo = new TransactionRepository();
        $tx = $repo->find($id, $this->hid);
        if (!$tx || !Auth::can((int) $tx['account_id'], 'book')) {
            $this->json(['error' => 'Kein Zugriff'], 403);
        }
        $cat = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        $repo->update($id, $this->hid, ['category_id' => $cat]);
        $this->json(['ok' => true]);
    }

    /** Mehrere Buchungen auf einmal kategorisieren oder löschen */
    public function bulk(): void
    {
        $ids = array_map('intval', $this->request->arr('ids'));
        $repo = new TransactionRepository();
        $action = $this->request->str('action');
        $cat = (new CategoryRepository())->validId($this->request->int('category_id'), $this->hid);
        $n = 0;
        foreach ($ids as $id) {
            $tx = $repo->find($id, $this->hid);
            if (!$tx || !Auth::can((int) $tx['account_id'], 'book')) {
                continue;
            }
            if ($action === 'delete') {
                $tx['transfer_group'] ? $repo->deleteTransferGroup($tx['transfer_group'], $this->hid) : $repo->delete($id, $this->hid);
            } elseif (!$tx['transfer_group']) {
                $repo->update($id, $this->hid, ['category_id' => $cat]);
            }
            $n++;
        }
        $this->back('/transactions', $action === 'delete' ? "$n Buchungen gelöscht." : "$n Buchungen kategorisiert.", 'success');
    }

    /** AJAX: Kategorie-Vorschlag zu Empfänger/Verwendungszweck */
    public function suggest(): void
    {
        $s = (new CategorizationService($this->hid))->suggestForTransaction($this->request->str('payee'), $this->request->str('purpose'));
        $this->json($s);
    }

    private function validated(): array
    {
        $r = $this->request;
        $kind = in_array($r->str('kind'), ['expense', 'income', 'transfer'], true) ? $r->str('kind') : 'expense';
        $cents = Money::parse($r->str('amount'));
        $data = [
            'kind'          => $kind,
            'amount'        => $r->str('amount'),
            'cents'         => $cents === null ? 0 : abs($cents),
            'account_id'    => (int) $r->int('account_id'),
            'to_account_id' => $kind === 'transfer' ? (int) $r->int('to_account_id') : null,
            'category_id'   => $kind === 'transfer' ? null : (new CategoryRepository())->validId($r->int('category_id'), $this->hid),
            'booking_date'  => valid_date($r->str('booking_date'), date('Y-m-d')),
            'payee'         => mb_substr($r->str('payee'), 0, 190),
            'purpose'       => mb_substr($r->str('purpose'), 0, 2000),
            'note'          => mb_substr($r->str('note'), 0, 255),
        ];
        $error = null;
        if (!$cents) {
            $error = 'Bitte einen Betrag eingeben.';
        } elseif (!Auth::can($data['account_id'], 'book')) {
            $error = 'Auf dieses Konto darfst du nicht buchen.';
        } elseif ($kind === 'transfer' && (!Auth::can($data['to_account_id'], 'book') || $data['to_account_id'] === $data['account_id'])) {
            $error = 'Bitte ein anderes Zielkonto wählen.';
        }
        return [$data, $error];
    }

    private function maybeCreateRule(array $data): void
    {
        if (!$this->request->bool('create_rule') || !$data['payee'] || !$data['category_id']) {
            return;
        }
        $rules = new RuleRepository();
        $value = mb_strtolower($data['payee']);
        if (!$rules->exists($this->hid, 'transaction', 'payee', 'contains', $value)) {
            $rules->create($this->hid, [
                'target' => 'transaction', 'field' => 'payee', 'operator' => 'contains', 'value' => $value,
                'category_id' => $data['category_id'], 'priority' => 50, 'learned' => 1,
            ]);
        }
    }

    private function renderForm(array $tx, string $title, ?string $error = null): void
    {
        $repo = new TransactionRepository();
        $this->view('transactions/form', [
            'title'      => $title,
            'back'       => '/transactions',
            'tx'         => $tx,
            'error'      => $error,
            'accounts'   => (new AccountRepository())->options($this->hid, Auth::accountIds('book')),
            'categories' => (new CategoryRepository())->all($this->hid),
            'payees'     => $repo->recentPayees($this->hid, Auth::accountIds('view')),
        ]);
    }
}
