<?php

declare(strict_types=1);

namespace App\Core;

use App\Repositories\UserRepository;

final class Auth
{
    private const MAX_FAILED = 5;
    private const LOCK_MINUTES = 15;

    private static ?array $user = null;
    private static array $accountCache = [];

    public static function attempt(string $email, string $password): string|true
    {
        $repo = new UserRepository();
        $user = $repo->findByEmail($email);
        if (!$user || !$user['active']) {
            // gleiche Laufzeit wie bei existierendem Benutzer (kein User-Enumeration über Timing)
            password_verify($password, '$2y$10$JYfUq6jw2HpKBx6LkIPcl.UjUFHFuklEcWXEb6.M/lq9BOjTQaLvW');
            return 'E-Mail oder Passwort ist falsch.';
        }
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            return 'Zu viele Fehlversuche. Bitte in ein paar Minuten erneut versuchen.';
        }
        if (!password_verify($password, $user['password_hash'])) {
            $failed = (int) $user['failed_logins'] + 1;
            $lock = $failed >= self::MAX_FAILED ? date('Y-m-d H:i:s', time() + self::LOCK_MINUTES * 60) : null;
            $repo->registerFailedLogin((int) $user['id'], $lock ? 0 : $failed, $lock);
            return 'E-Mail oder Passwort ist falsch.';
        }
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $repo->updatePassword((int) $user['id'], $password);
        }
        $repo->registerLogin((int) $user['id']);
        self::login($user);
        return true;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::set('user_id', (int) $user['id']);
        self::$user = null;
    }

    public static function logout(): void
    {
        Session::destroy();
        self::$user = null;
    }

    public static function user(): ?array
    {
        if (self::$user === null) {
            $id = Session::get('user_id');
            if (!$id) {
                return null;
            }
            $user = (new UserRepository())->find((int) $id);
            if (!$user || !$user['active']) {
                Session::forget('user_id');
                return null;
            }
            self::$user = $user;
        }
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function householdId(): int
    {
        return (int) (self::user()['household_id'] ?? 0);
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    public static function isChild(): bool
    {
        return (self::user()['role'] ?? '') === 'child';
    }

    public static function requireLogin(Request $request): void
    {
        if (self::user()) {
            return;
        }
        if ($request->wantsJson()) {
            Response::json(['error' => 'Nicht angemeldet'], 401);
        }
        Session::set('intended', $request->path);
        Response::redirect('/login');
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            http_response_code(403);
            View::render('errors/message', ['title' => 'Kein Zugriff', 'message' => 'Diese Seite ist nur für Administratoren.']);
            exit;
        }
    }

    /**
     * IDs aller Konten, die der aktuelle Benutzer sehen ('view') bzw. bebuchen ('book') darf.
     * @return int[]
     */
    public static function accountIds(string $ability = 'view'): array
    {
        if (isset(self::$accountCache[$ability])) {
            return self::$accountCache[$ability];
        }
        $db = Database::connection();
        if (self::isAdmin()) {
            $st = $db->prepare('SELECT id FROM accounts WHERE household_id = ?');
            $st->execute([self::householdId()]);
        } else {
            $col = $ability === 'book' ? 'p.can_book' : 'p.can_view';
            $st = $db->prepare("SELECT a.id FROM accounts a JOIN account_permissions p ON p.account_id = a.id
                                WHERE a.household_id = ? AND p.user_id = ? AND $col = 1");
            $st->execute([self::householdId(), self::id()]);
        }
        return self::$accountCache[$ability] = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function can(?int $accountId, string $ability = 'view'): bool
    {
        return $accountId !== null && in_array($accountId, self::accountIds($ability), true);
    }

    public static function authorize(?int $accountId, string $ability = 'view'): void
    {
        if (!self::can($accountId, $ability)) {
            http_response_code(403);
            View::render('errors/message', ['title' => 'Kein Zugriff', 'message' => 'Du hast keine Berechtigung für dieses Konto.']);
            exit;
        }
    }

    public static function flushCache(): void
    {
        self::$accountCache = [];
    }
}
