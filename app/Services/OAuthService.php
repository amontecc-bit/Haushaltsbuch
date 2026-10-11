<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * OAuth 2.1 für den MCP-Server (Authorization Code mit PKCE, dynamische Client-Registrierung, Refresh-Token).
 * Reine Hilfsfunktionen – Datenbankzugriff im OAuthRepository, Abläufe im OAuthController.
 */
final class OAuthService
{
    /** Einziger Scope: Einkaufsliste, Vorrat, Rezepte, Bargeld-Buchung */
    public const SCOPE = 'haushalt';

    public const CODE_TTL = 600;               // 10 Minuten
    public const ACCESS_TTL = 3600;            // 1 Stunde
    public const REFRESH_TTL = 90 * 86400;     // 90 Tage, wird bei jeder Erneuerung verlängert

    /** Rückruf-Adressen nur zu diesen Hosts (https) bzw. localhost (Claude Desktop/Code) */
    public const DEFAULT_REDIRECT_HOSTS = ['claude.ai', 'claude.com'];

    public static function token(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Tokens, Codes und Secrets werden nur als SHA-256 gespeichert */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** PKCE (RFC 7636, nur S256): BASE64URL(SHA256(verifier)) muss der beim Autorisieren gesendeten Challenge entsprechen */
    public static function verifyPkce(string $verifier, string $challenge): bool
    {
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier) || $challenge === '') {
            return false;
        }
        return hash_equals($challenge, self::base64url(hash('sha256', $verifier, true)));
    }

    public static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** @return string[] erlaubte Hosts für Rückruf-Adressen (MCP_REDIRECT_HOSTS, kommagetrennt) */
    public static function redirectHosts(): array
    {
        $cfg = trim((string) Config::get('mcp.redirect_hosts', ''));
        $hosts = $cfg !== '' ? array_map('trim', explode(',', $cfg)) : self::DEFAULT_REDIRECT_HOSTS;
        return array_values(array_filter(array_map('strtolower', $hosts)));
    }

    /**
     * Rückruf-Adresse zulässig? https zu einem erlaubten Host (oder dessen Subdomain) oder http(s) zu localhost
     * (beliebiger Port, für Desktop-Clients); kein Fragment, keine Zugangsdaten in der URL.
     */
    public static function redirectAllowed(string $uri, array $hosts): bool
    {
        if (mb_strlen($uri) > 500) {
            return false;
        }
        $p = parse_url($uri);
        if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['fragment']) || isset($p['user']) || isset($p['pass'])) {
            return false;
        }
        $scheme = strtolower($p['scheme']);
        $host = strtolower($p['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)) {
            return in_array($scheme, ['http', 'https'], true);
        }
        if ($scheme !== 'https') {
            return false;
        }
        foreach ($hosts as $h) {
            if ($host === $h || str_ends_with($host, '.' . $h)) {
                return true;
            }
        }
        return false;
    }

    /** Parameter an eine Rückruf-Adresse hängen (vorhandene Query bleibt erhalten) */
    public static function appendQuery(string $uri, array $params): string
    {
        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** Bearer-Token aus dem Authorization-Header (auch über die Umleitung von .htaccess bzw. CGI) */
    public static function bearer(array $server): ?string
    {
        $h = self::authorizationHeader($server);
        return preg_match('/^Bearer\s+([A-Za-z0-9\-._~+\/]+=*)$/i', $h, $m) ? $m[1] : null;
    }

    /** @return array{0:string, 1:string}|null client_id und Secret aus „Authorization: Basic …“ */
    public static function basicCredentials(array $server): ?array
    {
        $h = self::authorizationHeader($server);
        if (!preg_match('/^Basic\s+(\S+)$/i', $h, $m)) {
            return null;
        }
        $decoded = base64_decode($m[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$id, $secret] = explode(':', $decoded, 2);
        return [rawurldecode($id), rawurldecode($secret)];
    }

    /** Ressource (RFC 8707) passt zur Adresse des MCP-Servers? Leer = keine Angabe. Schema/Host ohne Groß-/Kleinschreibung. */
    public static function resourceMatches(string $given, string $expected): bool
    {
        if ($given === '') {
            return true;
        }
        $norm = static function (string $u): string {
            $p = parse_url($u) ?: [];
            return strtolower(($p['scheme'] ?? '') . '://' . ($p['host'] ?? '')) . (isset($p['port']) ? ':' . $p['port'] : '')
                . rtrim($p['path'] ?? '', '/');
        };
        return $norm($given) === $norm($expected);
    }

    public static function hasAuthorizationHeader(array $server): bool
    {
        return self::authorizationHeader($server) !== '';
    }

    private static function authorizationHeader(array $server): string
    {
        $h = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? $server['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($h === '' && function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $k => $v) {
                if (strcasecmp((string) $k, 'Authorization') === 0) {
                    $h = (string) $v;
                }
            }
        }
        return trim((string) $h);
    }
}
