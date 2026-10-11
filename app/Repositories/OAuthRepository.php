<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * OAuth-Clients, Autorisierungscodes, Verbindungen (Access-/Refresh-Token) und das MCP-Protokoll.
 * Tokens, Codes und Secrets liegen nur als SHA-256-Hash vor (siehe OAuthService::hash()).
 */
final class OAuthRepository extends Repository
{
    // ---- Clients -------------------------------------------------------------

    public function createClient(string $clientId, ?string $secretHash, string $name, array $redirectUris): int
    {
        return $this->insert('oauth_clients', [
            'client_id' => $clientId, 'secret_hash' => $secretHash, 'name' => mb_substr($name, 0, 190),
            'redirect_uris' => json_encode(array_values($redirectUris), JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @return array{id:int, client_id:string, secret_hash:?string, name:string, redirect_uris:string[]}|null */
    public function findClient(string $clientId): ?array
    {
        $c = $this->one('SELECT * FROM oauth_clients WHERE client_id = ?', [mb_substr($clientId, 0, 64)]);
        if ($c) {
            $c['id'] = (int) $c['id'];
            $c['redirect_uris'] = json_decode((string) $c['redirect_uris'], true) ?: [];
        }
        return $c;
    }

    // ---- Codes ---------------------------------------------------------------

    public function createCode(string $codeHash, int $clientId, int $userId, string $redirectUri, string $challenge, string $scope, int $ttl): void
    {
        $this->insert('oauth_codes', [
            'code_hash' => $codeHash, 'client_id' => $clientId, 'user_id' => $userId, 'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge, 'scope' => $scope, 'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
        ]);
    }

    /** Code einlösen: nur einmal möglich (wird dabei gelöscht), abgelaufene Codes gelten als ungültig */
    public function takeCode(string $codeHash): ?array
    {
        $code = $this->one('SELECT * FROM oauth_codes WHERE code_hash = ?', [$codeHash]);
        if (!$code || $this->exec('DELETE FROM oauth_codes WHERE id = ?', [$code['id']]) !== 1) {
            return null;
        }
        return strtotime($code['expires_at']) >= time() ? $code : null;
    }

    // ---- Verbindungen (Tokens) ----------------------------------------------

    public function createToken(int $clientId, int $userId, int $householdId, string $scope, string $accessHash, string $refreshHash,
                                int $accessTtl, int $refreshTtl): int
    {
        return $this->insert('oauth_tokens', [
            'client_id' => $clientId, 'user_id' => $userId, 'household_id' => $householdId, 'scope' => $scope,
            'access_hash' => $accessHash, 'access_expires_at' => date('Y-m-d H:i:s', time() + $accessTtl),
            'refresh_hash' => $refreshHash, 'refresh_expires_at' => date('Y-m-d H:i:s', time() + $refreshTtl),
        ]);
    }

    /** Gültige Verbindung zum Access-Token, mit Name des Clients */
    public function findByAccess(string $accessHash): ?array
    {
        return $this->one(
            'SELECT t.*, c.name AS client_name FROM oauth_tokens t JOIN oauth_clients c ON c.id = t.client_id
             WHERE t.access_hash = ? AND t.access_expires_at > NOW()',
            [$accessHash]
        );
    }

    public function findByRefresh(string $refreshHash): ?array
    {
        return $this->one('SELECT * FROM oauth_tokens WHERE refresh_hash = ? AND refresh_expires_at > NOW()', [$refreshHash]);
    }

    /** Neue Tokens für eine Verbindung; die alten werden damit ungültig. false, wenn der Refresh-Token schon benutzt wurde */
    public function rotate(int $id, string $oldRefreshHash, string $accessHash, string $refreshHash, int $accessTtl, int $refreshTtl): bool
    {
        return $this->exec(
            'UPDATE oauth_tokens SET access_hash = ?, access_expires_at = ?, refresh_hash = ?, refresh_expires_at = ?
             WHERE id = ? AND refresh_hash = ?',
            [$accessHash, date('Y-m-d H:i:s', time() + $accessTtl), $refreshHash, date('Y-m-d H:i:s', time() + $refreshTtl), $id, $oldRefreshHash]
        ) === 1;
    }

    public function touch(int $id): void
    {
        $this->exec('UPDATE oauth_tokens SET last_used_at = NOW() WHERE id = ?', [$id]);
    }

    /** Verbindungen des Haushalts (optional nur eines Benutzers) mit Client und Benutzer */
    public function connections(int $householdId, ?int $userId = null): array
    {
        return $this->many(
            'SELECT t.id, t.user_id, t.created_at, t.last_used_at, t.refresh_expires_at, c.name AS client_name, u.name AS user_name
             FROM oauth_tokens t JOIN oauth_clients c ON c.id = t.client_id JOIN users u ON u.id = t.user_id
             WHERE t.household_id = ? AND t.refresh_expires_at > NOW()' . ($userId ? ' AND t.user_id = ?' : '') . '
             ORDER BY COALESCE(t.last_used_at, t.created_at) DESC',
            $userId ? [$householdId, $userId] : [$householdId]
        );
    }

    /** Verbindung trennen; ohne $userId (Admin) jede des Haushalts */
    public function revoke(int $id, int $householdId, ?int $userId = null): int
    {
        return $this->exec(
            'DELETE FROM oauth_tokens WHERE id = ? AND household_id = ?' . ($userId ? ' AND user_id = ?' : ''),
            $userId ? [$id, $householdId, $userId] : [$id, $householdId]
        );
    }

    /** Abgelaufenes aufräumen; registrierte Clients ohne Verbindung nach einem Tag löschen */
    public function purge(): void
    {
        $this->exec('DELETE FROM oauth_codes WHERE expires_at < NOW()');
        $this->exec('DELETE FROM oauth_tokens WHERE refresh_expires_at < NOW()');
        $this->exec(
            'DELETE FROM oauth_clients WHERE created_at < NOW() - INTERVAL 1 DAY
               AND NOT EXISTS (SELECT 1 FROM oauth_tokens t WHERE t.client_id = oauth_clients.id)
               AND NOT EXISTS (SELECT 1 FROM oauth_codes k WHERE k.client_id = oauth_clients.id)'
        );
    }

    // ---- Protokoll -----------------------------------------------------------

    public function log(int $householdId, ?int $userId, ?string $clientName, string $tool, string $summary): void
    {
        $this->insert('mcp_log', [
            'household_id' => $householdId, 'user_id' => $userId, 'client_name' => $clientName !== null ? mb_substr($clientName, 0, 190) : null,
            'tool' => mb_substr($tool, 0, 60), 'summary' => mb_substr($summary, 0, 255),
        ]);
    }

    public function recentLog(int $householdId, int $limit = 30): array
    {
        return $this->many(
            'SELECT l.*, u.name AS user_name FROM mcp_log l LEFT JOIN users u ON u.id = l.user_id
             WHERE l.household_id = ? ORDER BY l.id DESC LIMIT ' . (int) $limit,
            [$householdId]
        );
    }
}
