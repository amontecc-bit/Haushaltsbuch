<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Repositories\OAuthRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\UserRepository;
use App\Services\OAuthService;

/**
 * OAuth 2.1 für den MCP-Server, damit Claude (Web, Smartphone-App, Desktop) sich als Benutzer anmelden kann:
 * Metadaten (RFC 8414/9728), dynamische Client-Registrierung (RFC 7591), Autorisierung mit Einwilligung
 * und PKCE, Token-Ausgabe mit Refresh-Token-Rotation.
 */
final class OAuthController extends Controller
{
    // ---- Metadaten -----------------------------------------------------------

    /** /.well-known/oauth-protected-resource – beschreibt den MCP-Server als geschützte Ressource */
    public function protectedResource(): void
    {
        $this->metadata([
            'resource'                 => absolute_url('/mcp'),
            'authorization_servers'    => [self::issuer()],
            'scopes_supported'         => [OAuthService::SCOPE],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Haushaltsbuch',
        ]);
    }

    /** /.well-known/oauth-authorization-server (und openid-configuration für Installationen im Unterordner) */
    public function authorizationServer(): void
    {
        $this->metadata([
            'issuer'                                => self::issuer(),
            'authorization_endpoint'                => absolute_url('/oauth/authorize'),
            'token_endpoint'                        => absolute_url('/oauth/token'),
            'registration_endpoint'                 => absolute_url('/oauth/register'),
            'scopes_supported'                      => [OAuthService::SCOPE],
            'response_types_supported'              => ['code'],
            'response_modes_supported'              => ['query'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    // ---- Registrierung -------------------------------------------------------

    /** Dynamische Client-Registrierung: nur Rückruf-Adressen zu erlaubten Hosts (claude.ai …) oder localhost */
    public function register(): void
    {
        $b = $this->request->all();
        $uris = $b['redirect_uris'] ?? null;
        if (!is_array($uris) || !$uris || count($uris) > 5) {
            $this->error('invalid_redirect_uri', 'redirect_uris fehlt.');
        }
        $hosts = OAuthService::redirectHosts();
        foreach ($uris as $u) {
            if (!is_string($u) || !OAuthService::redirectAllowed($u, $hosts)) {
                $this->error('invalid_redirect_uri', 'Diese Rückruf-Adresse ist nicht zugelassen.');
            }
        }
        $method = is_string($b['token_endpoint_auth_method'] ?? null) ? $b['token_endpoint_auth_method'] : 'none';
        if (!in_array($method, ['none', 'client_secret_post', 'client_secret_basic'], true)) {
            $this->error('invalid_client_metadata', 'Nicht unterstützte Authentifizierung am Token-Endpunkt.');
        }
        $grants = $b['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (!is_array($grants) || array_diff($grants, ['authorization_code', 'refresh_token'])) {
            $this->error('invalid_client_metadata', 'Nicht unterstützter grant_type.');
        }
        $name = is_string($b['client_name'] ?? null) ? trim($b['client_name']) : '';
        $name = mb_substr($name !== '' ? $name : 'Unbekannte Anwendung', 0, 190);

        $clientId = 'hb_' . bin2hex(random_bytes(16));
        $secret = $method === 'none' ? null : OAuthService::token();
        (new OAuthRepository())->createClient($clientId, $secret !== null ? OAuthService::hash($secret) : null, $name, $uris);

        header('Cache-Control: no-store');
        Response::json(array_filter([
            'client_id'                  => $clientId,
            'client_secret'              => $secret,
            'client_id_issued_at'        => time(),
            'client_secret_expires_at'   => $secret !== null ? 0 : null,
            'client_name'                => $name,
            'redirect_uris'              => array_values($uris),
            'token_endpoint_auth_method' => $method,
            'grant_types'                => array_values($grants),
            'response_types'             => ['code'],
            'scope'                      => OAuthService::SCOPE,
        ], fn ($v) => $v !== null), 201);
    }

    // ---- Autorisierung -------------------------------------------------------

    /** Einwilligungsseite (nach Anmeldung) */
    public function authorize(): void
    {
        $req = $this->authorizationRequest();
        if (!Auth::user()) {
            Session::set('intended', '/oauth/authorize?' . http_build_query($req['params']));
            $this->redirect('/login');
        }
        $this->requireEnabled();
        View::render('oauth/authorize', [
            'title'  => 'Zugriff erlauben',
            'client' => $req['client'],
            'host'   => (string) parse_url($req['redirect_uri'], PHP_URL_HOST),
            'params' => $req['params'],
            'user'   => Auth::user(),
        ], 'layout_guest');
    }

    /** Entscheidung auf der Einwilligungsseite → zurück zum Client mit Code oder access_denied */
    public function decide(): void
    {
        $req = $this->authorizationRequest();
        $this->requireEnabled();
        if ($this->request->str('decision') !== 'allow') {
            $this->redirectError($req, 'access_denied', 'Zugriff abgelehnt.');
        }
        $code = OAuthService::token();
        (new OAuthRepository())->createCode(OAuthService::hash($code), $req['client']['id'], Auth::id(), $req['redirect_uri'],
            $req['params']['code_challenge'], OAuthService::SCOPE, OAuthService::CODE_TTL);
        Response::redirect(OAuthService::appendQuery($req['redirect_uri'], array_filter([
            'code' => $code, 'state' => $req['params']['state'] ?? null, 'iss' => self::issuer(),
        ], fn ($v) => $v !== null && $v !== '')));
    }

    // ---- Token ---------------------------------------------------------------

    public function token(): void
    {
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        $repo = new OAuthRepository();
        if (random_int(1, 20) === 1) {
            $repo->purge();
        }
        $client = $this->authenticateClient($repo);
        match ($this->request->str('grant_type')) {
            'authorization_code' => $this->exchangeCode($repo, $client),
            'refresh_token'      => $this->refresh($repo, $client),
            default              => $this->error('unsupported_grant_type', 'Nur authorization_code und refresh_token.'),
        };
    }

    // ---------------------------------------------------------------------

    private function exchangeCode(OAuthRepository $repo, array $client): never
    {
        $r = $this->request;
        $code = $repo->takeCode(OAuthService::hash($r->str('code')));
        if (!$code || (int) $code['client_id'] !== $client['id']) {
            $this->error('invalid_grant', 'Code ungültig oder abgelaufen.');
        }
        if ($r->str('redirect_uri') !== '' && $r->str('redirect_uri') !== $code['redirect_uri']) {
            $this->error('invalid_grant', 'redirect_uri passt nicht.');
        }
        if (!OAuthService::verifyPkce($r->str('code_verifier'), $code['code_challenge'])) {
            $this->error('invalid_grant', 'PKCE-Prüfung fehlgeschlagen.');
        }
        $user = $this->activeUser((int) $code['user_id']);
        $access = OAuthService::token();
        $refresh = OAuthService::token();
        $repo->createToken($client['id'], (int) $user['id'], (int) $user['household_id'], $code['scope'],
            OAuthService::hash($access), OAuthService::hash($refresh), OAuthService::ACCESS_TTL, OAuthService::REFRESH_TTL);
        $this->tokenResponse($access, $refresh, $code['scope']);
    }

    private function refresh(OAuthRepository $repo, array $client): never
    {
        $oldHash = OAuthService::hash($this->request->str('refresh_token'));
        $row = $repo->findByRefresh($oldHash);
        if (!$row || (int) $row['client_id'] !== $client['id']) {
            $this->error('invalid_grant', 'Refresh-Token ungültig oder abgelaufen.');
        }
        $user = $this->activeUser((int) $row['user_id']);
        if ((int) $user['household_id'] !== (int) $row['household_id']) {
            $this->error('invalid_grant', 'Benutzer gehört nicht mehr zum Haushalt.');
        }
        $access = OAuthService::token();
        $refresh = OAuthService::token();
        if (!$repo->rotate((int) $row['id'], $oldHash, OAuthService::hash($access), OAuthService::hash($refresh),
            OAuthService::ACCESS_TTL, OAuthService::REFRESH_TTL)) {
            $this->error('invalid_grant', 'Refresh-Token wurde bereits verwendet.');
        }
        $this->tokenResponse($access, $refresh, $row['scope']);
    }

    private function tokenResponse(string $access, string $refresh, string $scope): never
    {
        Response::json([
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => OAuthService::ACCESS_TTL,
            'refresh_token' => $refresh,
            'scope'         => $scope,
        ]);
    }

    /** Benutzer muss aktiv sein und der Haushalt den Claude-Zugriff erlauben */
    private function activeUser(int $userId): array
    {
        $user = (new UserRepository())->find($userId);
        if (!$user || !$user['active']) {
            $this->error('invalid_grant', 'Benutzer gesperrt oder gelöscht.');
        }
        if (!self::enabled((int) $user['household_id'])) {
            $this->error('invalid_grant', 'Der Claude-Zugriff ist für diesen Haushalt ausgeschaltet.');
        }
        return $user;
    }

    /** Client per Basic-Auth oder client_id/client_secret im Formular; öffentliche Clients nur mit client_id */
    private function authenticateClient(OAuthRepository $repo): array
    {
        $basic = OAuthService::basicCredentials($_SERVER);
        [$id, $secret] = $basic ?? [$this->request->str('client_id'), $this->request->str('client_secret')];
        $client = $id !== '' ? $repo->findClient($id) : null;
        if (!$client) {
            $this->error('invalid_client', 'Unbekannter Client.', 401);
        }
        if ($client['secret_hash'] !== null && !hash_equals($client['secret_hash'], OAuthService::hash((string) $secret))) {
            $this->error('invalid_client', 'Client-Secret falsch.', 401);
        }
        return $client;
    }

    /**
     * Parameter der Autorisierungsanfrage prüfen. Unbekannter Client oder falsche Rückruf-Adresse → Fehlerseite
     * (niemals dorthin umleiten), alle anderen Fehler → Umleitung mit error-Parameter.
     * @return array{client:array, redirect_uri:string, params:array<string,string>}
     */
    private function authorizationRequest(): array
    {
        $params = [];
        foreach (['response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource'] as $k) {
            $v = $this->request->input($k);
            if (is_string($v) && $v !== '') {
                $params[$k] = mb_substr($v, 0, 1000);
            }
        }
        $client = isset($params['client_id']) ? (new OAuthRepository())->findClient($params['client_id']) : null;
        if (!$client) {
            $this->page('Unbekannte Anwendung', 'Diese Anwendung ist nicht registriert. Bitte die Verbindung in Claude neu hinzufügen.');
        }
        $redirect = $params['redirect_uri'] ?? (count($client['redirect_uris']) === 1 ? $client['redirect_uris'][0] : '');
        if (!in_array($redirect, $client['redirect_uris'], true)) {
            $this->page('Ungültige Anfrage', 'Die Rückruf-Adresse passt nicht zur Anwendung.');
        }
        $req = ['client' => $client, 'redirect_uri' => $redirect, 'params' => $params];

        if (($params['response_type'] ?? '') !== 'code') {
            $this->redirectError($req, 'unsupported_response_type', 'Nur response_type=code.');
        }
        if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $params['code_challenge'] ?? '') || ($params['code_challenge_method'] ?? '') !== 'S256') {
            $this->redirectError($req, 'invalid_request', 'PKCE mit S256 ist erforderlich.');
        }
        if (!OAuthService::resourceMatches($params['resource'] ?? '', absolute_url('/mcp'))) {
            $this->redirectError($req, 'invalid_target', 'Unbekannte Ressource.');
        }
        return $req;
    }

    private function requireEnabled(): void
    {
        if (!self::enabled($this->hid)) {
            $this->page('Claude-Zugriff ausgeschaltet', Auth::isAdmin()
                ? 'Bitte zuerst unter Einstellungen › Claude (MCP) den Zugriff für den Haushalt einschalten und dann die Verbindung erneut starten.'
                : 'Ein Administrator muss den Zugriff zuerst unter Einstellungen › Claude (MCP) einschalten.');
        }
    }

    public static function enabled(int $householdId): bool
    {
        return $householdId > 0 && (new SettingsRepository())->get($householdId, 'mcp_enabled') === '1';
    }

    public static function issuer(): string
    {
        return rtrim(absolute_url('/'), '/');
    }

    private function redirectError(array $req, string $error, string $description): never
    {
        Response::redirect(OAuthService::appendQuery($req['redirect_uri'], array_filter([
            'error' => $error, 'error_description' => $description, 'state' => $req['params']['state'] ?? null, 'iss' => self::issuer(),
        ], fn ($v) => $v !== null)));
    }

    private function page(string $title, string $message): never
    {
        http_response_code(400);
        View::render('errors/message', ['title' => $title, 'message' => $message], 'layout_guest');
        exit;
    }

    private function metadata(array $data): never
    {
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: public, max-age=3600');
        Response::json($data);
    }

    private function error(string $error, string $description, int $status = 400): never
    {
        if ($status === 401) {
            header('WWW-Authenticate: Basic realm="Haushaltsbuch"');
        }
        Response::json(['error' => $error, 'error_description' => $description], $status);
    }
}
