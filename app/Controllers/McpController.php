<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Response;
use App\Repositories\OAuthRepository;
use App\Repositories\UserRepository;
use App\Services\Mcp\McpServer;
use App\Services\Mcp\McpTools;
use App\Services\OAuthService;

/**
 * MCP-Endpunkt (Streamable HTTP, zustandslos): POST /mcp mit JSON-RPC, Anmeldung per OAuth-Bearer-Token.
 * Ohne gültiges Token 401 mit Verweis auf die Metadaten – daran erkennt Claude, dass es sich anmelden muss.
 */
final class McpController extends Controller
{
    public function handle(): void
    {
        $connection = $this->authenticate();
        $user = (new UserRepository())->find((int) $connection['user_id']);
        Auth::actAs($user);
        $this->hid = (int) $user['household_id'];
        (new OAuthRepository())->touch((int) $connection['id']);

        $tools = new McpTools($this->hid, (int) $user['id'], $connection['client_name']);
        $server = new McpServer($tools->all(), McpTools::instructions());

        $body = (string) file_get_contents('php://input');
        $payload = json_decode($body, true);
        $response = json_last_error() === JSON_ERROR_NONE
            ? $server->handle($payload)
            : ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']];

        if ($response === null) {
            http_response_code(202); // nur Benachrichtigungen
            exit;
        }
        Response::json($response);
    }

    /** Kein Server-Stream (SSE) – der Client soll nur POST verwenden */
    public function stream(): void
    {
        header('Allow: POST');
        http_response_code(405);
        exit;
    }

    /** Gültige Verbindung zum Bearer-Token; Benutzer aktiv, im selben Haushalt und Claude-Zugriff eingeschaltet */
    private function authenticate(): array
    {
        $token = OAuthService::bearer($_SERVER);
        $connection = $token !== null ? (new OAuthRepository())->findByAccess(OAuthService::hash($token)) : null;
        if ($connection) {
            $user = (new UserRepository())->find((int) $connection['user_id']);
            if ($user && $user['active'] && (int) $user['household_id'] === (int) $connection['household_id']
                && OAuthController::enabled((int) $user['household_id'])) {
                return $connection;
            }
        }
        // Grund ins Fehlerlog (ohne Token), damit sich Verbindungsprobleme mit Claude nachvollziehen lassen
        error_log('MCP 401: ' . match (true) {
            $token === null && OAuthService::hasAuthorizationHeader($_SERVER) => 'Authorization-Header ohne gültiges Bearer-Token',
            $token === null => 'kein Authorization-Header angekommen (Weitergabe per .htaccess prüfen)',
            $connection === null => 'Token unbekannt oder abgelaufen',
            default => 'Benutzer gesperrt, anderer Haushalt oder Claude-Zugriff ausgeschaltet',
        });
        header('WWW-Authenticate: Bearer resource_metadata="' . absolute_url('/.well-known/oauth-protected-resource') . '"'
            . ($token !== null ? ', error="invalid_token"' : '') . ', scope="' . OAuthService::SCOPE . '"');
        Response::json(['error' => $token !== null ? 'invalid_token' : 'unauthorized'], 401);
    }
}
