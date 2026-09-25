<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\UserRepository;

final class UserController extends Controller
{
    public function index(): void
    {
        $actions = '<a href="' . e(url('/users/new')) . '" class="btn btn-primary"><i class="bi bi-person-plus"></i> Person hinzufügen</a>';
        $this->view('users/index', ['title' => 'Familie & Benutzer', 'users' => (new UserRepository())->all($this->hid), 'actions' => $actions]);
    }

    public function create(): void
    {
        $this->renderForm(['id' => null, 'name' => '', 'email' => '', 'role' => 'member', 'active' => 1], $this->defaultPerms('member'));
    }

    public function store(): void
    {
        $repo = new UserRepository();
        [$data, $error] = $this->validated(null);
        $password = $this->request->str('password');
        if (!$error && mb_strlen($password) < 8) {
            $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($error) {
            $this->renderForm(['id' => null] + $data, $this->permInput(), $error);
            return;
        }
        $id = $repo->create($this->hid, $data['name'], $data['email'], $password, $data['role']);
        $this->savePerms($id, $data['role']);
        $this->redirect('/users', $data['name'] . ' wurde hinzugefügt.');
    }

    public function edit(int $id): void
    {
        $user = (new UserRepository())->findInHousehold($id, $this->hid) ?? $this->notFound();
        $perms = [];
        foreach ((new AccountRepository())->withBalances($this->hid, Auth::accountIds('view'), true) as $a) {
            $p = (new AccountRepository())->permissions((int) $a['id'])[$id] ?? null;
            $perms[(int) $a['id']] = ['name' => $a['name'], 'can_view' => (bool) ($p['can_view'] ?? false), 'can_book' => (bool) ($p['can_book'] ?? false)];
        }
        $this->renderForm($user, $perms);
    }

    public function update(int $id): void
    {
        $repo = new UserRepository();
        $user = $repo->findInHousehold($id, $this->hid) ?? $this->notFound();
        [$data, $error] = $this->validated($id);
        $data['active'] = $this->request->bool('active') ? 1 : 0;
        if (!$error && $user['role'] === 'admin' && ($data['role'] !== 'admin' || !$data['active']) && $repo->adminCount($this->hid) <= 1) {
            $error = 'Es muss mindestens einen aktiven Administrator geben.';
        }
        $password = $this->request->str('password');
        if (!$error && $password !== '' && mb_strlen($password) < 8) {
            $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        }
        if ($error) {
            $this->renderForm(['id' => $id] + $data, $this->permInput(), $error);
            return;
        }
        $repo->update($id, $this->hid, $data);
        if ($password !== '') {
            $repo->updatePassword($id, $password);
        }
        $this->savePerms($id, $data['role']);
        $this->redirect('/users', 'Gespeichert.');
    }

    private function validated(?int $id): array
    {
        $r = $this->request;
        $data = [
            'name'  => mb_substr($r->str('name'), 0, 100),
            'email' => mb_strtolower($r->str('email')),
            'role'  => in_array($r->str('role'), ['admin', 'member', 'child'], true) ? $r->str('role') : 'member',
        ];
        $error = null;
        if ($data['name'] === '') {
            $error = 'Bitte einen Namen angeben.';
        } elseif (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } else {
            $other = (new UserRepository())->findByEmail($data['email']);
            if ($other && (int) $other['id'] !== $id) {
                $error = 'Diese E-Mail-Adresse wird bereits verwendet.';
            }
        }
        return [$data, $error];
    }

    private function defaultPerms(string $role): array
    {
        $out = [];
        foreach ((new AccountRepository())->withBalances($this->hid, Auth::accountIds('view')) as $a) {
            $out[(int) $a['id']] = ['name' => $a['name'], 'can_view' => $role !== 'child', 'can_book' => $role !== 'child'];
        }
        return $out;
    }

    private function permInput(): array
    {
        $view = $this->request->arr('perm_view');
        $book = $this->request->arr('perm_book');
        $out = [];
        foreach ((new AccountRepository())->withBalances($this->hid, Auth::accountIds('view'), true) as $a) {
            $aid = (int) $a['id'];
            $out[$aid] = ['name' => $a['name'], 'can_view' => isset($view[$aid]) || isset($book[$aid]), 'can_book' => isset($book[$aid])];
        }
        return $out;
    }

    private function savePerms(int $userId, string $role): void
    {
        $db = Database::connection();
        $db->prepare('DELETE p FROM account_permissions p JOIN accounts a ON a.id = p.account_id WHERE p.user_id = ? AND a.household_id = ?')
            ->execute([$userId, $this->hid]);
        if ($role === 'admin') {
            return; // Admins haben immer Zugriff
        }
        $ins = $db->prepare('INSERT INTO account_permissions (account_id, user_id, can_view, can_book) VALUES (?, ?, 1, ?)');
        foreach ($this->permInput() as $aid => $p) {
            if ($p['can_view']) {
                $ins->execute([$aid, $userId, $p['can_book'] ? 1 : 0]);
            }
        }
    }

    private function renderForm(array $user, array $perms, ?string $error = null): void
    {
        $this->view('users/form', [
            'title' => $user['id'] ? 'Person bearbeiten' : 'Person hinzufügen', 'back' => '/users',
            'u' => $user, 'perms' => $perms, 'error' => $error,
        ]);
    }
}
