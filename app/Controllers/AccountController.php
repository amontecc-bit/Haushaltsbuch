<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Money;
use App\Repositories\AccountRepository;
use App\Repositories\UserRepository;

final class AccountController extends Controller
{
    private const TYPES = ['giro', 'savings', 'cash', 'credit_card', 'other'];

    public function index(): void
    {
        $repo = new AccountRepository();
        $accounts = $repo->withBalances($this->hid, Auth::accountIds('view'), true);
        $total = array_sum(array_map(fn ($a) => $a['archived'] ? 0 : Money::toCents($a['balance']), $accounts));
        $actions = Auth::isAdmin()
            ? '<a href="' . e(url('/accounts/new')) . '" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Konto</a>'
            : '';
        $this->view('accounts/index', ['title' => 'Konten', 'accounts' => $accounts, 'total' => $total, 'actions' => $actions]);
    }

    public function create(): void
    {
        $users = (new UserRepository())->all($this->hid);
        $perms = [];
        foreach ($users as $u) {
            $perms[(int) $u['id']] = ['can_view' => $u['role'] !== 'child', 'can_book' => $u['role'] !== 'child'];
        }
        $account = ['name' => '', 'type' => 'giro', 'bank' => '', 'iban' => '', 'opening_balance' => '0', 'opening_date' => date('Y-m-d'),
            'color' => '#0d6efd', 'include_in_forecast' => 1, 'archived' => 0];
        $this->view('accounts/form', ['title' => 'Neues Konto', 'back' => '/accounts', 'account' => $account, 'users' => $users, 'perms' => $perms, 'error' => null]);
    }

    public function store(): void
    {
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm(null, $data, $error);
            return;
        }
        $repo = new AccountRepository();
        $id = $repo->create($this->hid, $data);
        $repo->setPermissions($id, $this->permissionInput());
        $this->redirect('/accounts', 'Konto „' . $data['name'] . '“ angelegt.');
    }

    public function edit(int $id): void
    {
        $repo = new AccountRepository();
        $account = $repo->find($id, $this->hid) ?? $this->notFound();
        $this->view('accounts/form', [
            'title' => 'Konto bearbeiten', 'back' => '/accounts', 'account' => $account,
            'users' => (new UserRepository())->all($this->hid), 'perms' => $repo->permissions($id), 'error' => null,
            'txCount' => $repo->transactionCount($id),
        ]);
    }

    public function update(int $id): void
    {
        $repo = new AccountRepository();
        $account = $repo->find($id, $this->hid) ?? $this->notFound();
        [$data, $error] = $this->validated();
        if ($error) {
            $this->renderForm($account, $data, $error);
            return;
        }
        $repo->update($id, $this->hid, $data);
        $repo->setPermissions($id, $this->permissionInput());
        $this->redirect('/accounts', 'Konto gespeichert.');
    }

    public function delete(int $id): void
    {
        $repo = new AccountRepository();
        $account = $repo->find($id, $this->hid) ?? $this->notFound();
        if ($repo->transactionCount($id) > 0) {
            $repo->update($id, $this->hid, ['archived' => 1]);
            $this->redirect('/accounts', 'Das Konto hat Buchungen und wurde deshalb archiviert statt gelöscht.', 'warning');
        }
        $repo->delete($id, $this->hid);
        $this->redirect('/accounts', 'Konto „' . $account['name'] . '“ gelöscht.');
    }

    private function validated(): array
    {
        $r = $this->request;
        $data = [
            'name'                => mb_substr($r->str('name'), 0, 100),
            'type'                => in_array($r->str('type'), self::TYPES, true) ? $r->str('type') : 'giro',
            'bank'                => mb_substr($r->str('bank'), 0, 100) ?: null,
            'iban'                => strtoupper(str_replace(' ', '', $r->str('iban'))) ?: null,
            'opening_balance'     => Money::toDecimal(Money::parse($r->str('opening_balance')) ?? 0),
            'opening_date'        => valid_date($r->str('opening_date'), date('Y-m-d')),
            'color'               => preg_match('/^#[0-9a-f]{6}$/i', $r->str('color')) ? $r->str('color') : '#0d6efd',
            'include_in_forecast' => $r->bool('include_in_forecast') ? 1 : 0,
            'archived'            => $r->bool('archived') ? 1 : 0,
        ];
        $error = $data['name'] === '' ? 'Bitte einen Namen angeben.' : null;
        return [$data, $error];
    }

    /** @return array<int, array{can_view:bool, can_book:bool}> */
    private function permissionInput(): array
    {
        $view = $this->request->arr('perm_view');
        $book = $this->request->arr('perm_book');
        $out = [];
        foreach ((new UserRepository())->all($this->hid) as $u) {
            $uid = (int) $u['id'];
            $out[$uid] = ['can_view' => isset($view[$uid]) || isset($book[$uid]), 'can_book' => isset($book[$uid])];
        }
        return $out;
    }

    private function renderForm(?array $account, array $data, string $error): void
    {
        $perms = [];
        foreach ($this->permissionInput() as $uid => $p) {
            $perms[$uid] = $p;
        }
        $this->view('accounts/form', [
            'title' => $account ? 'Konto bearbeiten' : 'Neues Konto', 'back' => '/accounts',
            'account' => ($account ?? []) + $data, 'users' => (new UserRepository())->all($this->hid),
            'perms' => $perms, 'error' => $error,
        ]);
    }
}
