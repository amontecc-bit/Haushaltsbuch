<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CategoryRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\UserRepository;
use App\Services\Migrator;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        if ($this->needsSetup()) {
            $this->redirect('/setup');
        }
        if (Auth::user()) {
            $this->redirect('/');
        }
        View::render('auth/login', ['title' => 'Anmelden', 'email' => ''], 'layout_guest');
    }

    public function login(): void
    {
        $email = $this->request->str('email');
        $result = Auth::attempt($email, $this->request->str('password'));
        if ($result !== true) {
            Session::flash('danger', $result);
            View::render('auth/login', ['title' => 'Anmelden', 'email' => $email], 'layout_guest');
            return;
        }
        $intended = Session::get('intended', '/');
        Session::forget('intended');
        $this->redirect(is_string($intended) && str_starts_with($intended, '/') ? $intended : '/');
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect('/login');
    }

    public function showSetup(): void
    {
        if (!$this->needsSetup()) {
            $this->redirect('/login');
        }
        View::render('auth/setup', ['title' => 'Ersteinrichtung', 'error' => null, 'old' => []], 'layout_guest');
    }

    public function setup(): void
    {
        if (!$this->needsSetup()) {
            $this->redirect('/login');
        }
        $old = [
            'household' => $this->request->str('household'),
            'name'      => $this->request->str('name'),
            'email'     => $this->request->str('email'),
        ];
        $password = $this->request->str('password');
        $error = null;
        if ($old['household'] === '' || $old['name'] === '') {
            $error = 'Bitte Haushaltsname und deinen Namen angeben.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'Bitte eine gültige E-Mail-Adresse angeben.';
        } elseif (mb_strlen($password) < 8) {
            $error = 'Das Passwort muss mindestens 8 Zeichen lang sein.';
        } elseif ($password !== $this->request->str('password_confirm')) {
            $error = 'Die Passwörter stimmen nicht überein.';
        }
        if ($error) {
            View::render('auth/setup', ['title' => 'Ersteinrichtung', 'error' => $error, 'old' => $old], 'layout_guest');
            return;
        }

        Migrator::run();
        $db = Database::connection();
        $db->beginTransaction();
        $hid = (new HouseholdRepository())->create($old['household']);
        $uid = (new UserRepository())->create($hid, $old['name'], $old['email'], $password, 'admin');
        (new CategoryRepository())->seedDefaults($hid);
        $db->commit();

        Auth::login(['id' => $uid]);
        $this->redirect('/accounts/new', 'Willkommen! Lege als Erstes dein erstes Konto an.');
    }

    private function needsSetup(): bool
    {
        try {
            if (Migrator::pending()) {
                Migrator::run();
            }
            return (new UserRepository())->count() === 0;
        } catch (\PDOException $e) {
            View::render('errors/message', [
                'title'   => 'Datenbank nicht erreichbar',
                'message' => 'Bitte die Zugangsdaten in der Datei .env prüfen.'
                    . (\App\Core\Config::get('app.debug') ? ' (' . $e->getMessage() . ')' : ''),
            ], 'layout_guest');
            exit;
        }
    }
}
