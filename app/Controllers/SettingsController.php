<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Repositories\AccountRepository;
use App\Repositories\HouseholdRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserPreferenceRepository;
use App\Repositories\UserRepository;

final class SettingsController extends Controller
{
    public function index(): void
    {
        $settings = (new SettingsRepository())->all($this->hid);
        $this->view('settings/index', [
            'title'      => 'Einstellungen',
            'settings'   => $settings,
            'household'  => (new HouseholdRepository())->find($this->hid),
            'isAdmin'    => Auth::isAdmin(),
            'envKey'     => (string) Config::get('ai.api_key') !== '',
            'hasKey'     => $settings['ai_api_key'] !== '' || (string) Config::get('ai.api_key') !== '',
            'defaultModel' => Config::get('ai.model'),
            'prefs'      => (new UserPreferenceRepository())->all(Auth::id()),
            'prefLabels' => UserPreferenceRepository::ACCOUNTS,
            'bookable'   => (new AccountRepository())->options($this->hid, Auth::accountIds('book')),
        ]);
    }

    public function update(): void
    {
        $r = $this->request;
        $repo = new SettingsRepository();
        $name = mb_substr($r->str('household_name'), 0, 120);
        if ($name !== '') {
            (new HouseholdRepository())->rename($this->hid, $name);
        }
        $repo->set($this->hid, 'ocr_mode', $r->str('ocr_mode') === 'ai' ? 'ai' : 'local');
        $key = $r->str('ai_api_key');
        if ($r->bool('ai_key_remove')) {
            $repo->set($this->hid, 'ai_api_key', '');
        } elseif ($key !== '') {
            $repo->set($this->hid, 'ai_api_key', $key);
        }
        $model = $r->str('ai_model');
        $repo->set($this->hid, 'ai_model', preg_match('/^[a-z0-9.\-]{0,64}$/', $model) ? $model : '');
        $repo->set($this->hid, 'forecast_months', (string) max(1, min(60, (int) $r->int('forecast_months', 12))));
        $repo->set($this->hid, 'forecast_avg_months', (string) max(1, min(24, (int) $r->int('forecast_avg_months', 6))));
        $this->redirect('/settings', 'Einstellungen gespeichert.');
    }

    /** Persönliche Vorzugskonten (für jeden Benutzer, nur bebuchbare Konten) */
    public function preferences(): void
    {
        $repo = new UserPreferenceRepository();
        $allowed = Auth::accountIds('book');
        foreach (array_keys(UserPreferenceRepository::ACCOUNTS) as $key) {
            $id = (int) $this->request->int($key);
            $repo->set(Auth::id(), $key, $id && in_array($id, $allowed, true) ? (string) $id : null);
        }
        $this->redirect('/settings', 'Vorzugskonten gespeichert.');
    }

    public function password(): void
    {
        $user = Auth::user();
        $current = $this->request->str('current');
        $new = $this->request->str('new');
        if (!password_verify($current, $user['password_hash'])) {
            $this->redirect('/settings', 'Das aktuelle Passwort ist falsch.', 'danger');
        }
        if (mb_strlen($new) < 8 || $new !== $this->request->str('confirm')) {
            $this->redirect('/settings', 'Das neue Passwort muss mindestens 8 Zeichen haben und zweimal gleich eingegeben werden.', 'danger');
        }
        (new UserRepository())->updatePassword((int) $user['id'], $new);
        $this->redirect('/settings', 'Passwort geändert.');
    }

    /** Übersichtsseite „Mehr“ für die mobile Navigation */
    public function more(): void
    {
        $this->view('settings/more', ['title' => 'Mehr', 'isAdmin' => Auth::isAdmin()]);
    }
}
