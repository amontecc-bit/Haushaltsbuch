<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/**
 * MCP-Protokoll (JSON-RPC 2.0, Streamable HTTP ohne Sitzung): initialize, ping, tools/list, tools/call.
 * Kennt keine Datenbank – die Tools kommen fertig mit Handler herein (siehe McpTools).
 */
final class McpServer
{
    /** Unterstützte Protokollversionen, neueste zuerst */
    public const VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * @param array<string, array{title:string, description:string, inputSchema:array, annotations?:array, handler:callable}> $tools
     */
    public function __construct(
        private readonly array $tools,
        private readonly string $instructions = '',
        private readonly array $serverInfo = ['name' => 'haushaltsbuch', 'title' => 'Haushaltsbuch', 'version' => '1.0.0'],
    ) {
    }

    /**
     * Eine Nachricht oder ein Batch. null = keine Antwort nötig (nur Benachrichtigungen bzw. Antworten des Clients).
     */
    public function handle(mixed $payload): ?array
    {
        if (is_array($payload) && $payload !== [] && array_is_list($payload)) {
            $out = array_values(array_filter(array_map(fn ($m) => $this->message($m), $payload)));
            return $out ?: null;
        }
        return $this->message($payload);
    }

    private function message(mixed $m): ?array
    {
        if (!is_array($m) || ($m['jsonrpc'] ?? null) !== '2.0') {
            return self::error(null, -32600, 'Invalid Request');
        }
        if (!isset($m['method'])) {
            return null; // Antwort des Clients auf eine Anfrage des Servers – stellen wir nicht
        }
        if (!is_string($m['method'])) {
            return self::error($m['id'] ?? null, -32600, 'Invalid Request');
        }
        if (!array_key_exists('id', $m)) {
            return null; // Benachrichtigung (z. B. notifications/initialized)
        }
        $id = $m['id'];
        $params = is_array($m['params'] ?? null) ? $m['params'] : [];
        try {
            $result = match ($m['method']) {
                'initialize' => $this->initialize($params),
                'ping'       => new \stdClass(),
                'tools/list' => ['tools' => $this->listTools()],
                'tools/call' => $this->callTool($params),
                default      => throw new McpError('Method not found: ' . $m['method'], -32601),
            };
        } catch (McpError $e) {
            return self::error($id, $e->getCode(), $e->getMessage());
        }
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;
        return array_filter([
            'protocolVersion' => in_array($requested, self::VERSIONS, true) ? $requested : self::VERSIONS[0],
            'capabilities'    => ['tools' => ['listChanged' => false]],
            'serverInfo'      => $this->serverInfo,
            'instructions'    => $this->instructions,
        ], fn ($v) => $v !== '');
    }

    private function listTools(): array
    {
        $out = [];
        foreach ($this->tools as $name => $t) {
            $schema = $t['inputSchema'];
            $schema['properties'] = (object) ($schema['properties'] ?? []);
            $out[] = array_filter([
                'name'        => $name,
                'title'       => $t['title'],
                'description' => $t['description'],
                'inputSchema' => $schema,
                'annotations' => isset($t['annotations']) ? ['title' => $t['title']] + $t['annotations'] : null,
            ], fn ($v) => $v !== null);
        }
        return $out;
    }

    private function callTool(array $params): array
    {
        $name = $params['name'] ?? null;
        $tool = is_string($name) ? ($this->tools[$name] ?? null) : null;
        if (!$tool) {
            throw new McpError('Unknown tool: ' . (is_string($name) ? $name : '?'), -32602);
        }
        $args = $params['arguments'] ?? [];
        if (!is_array($args)) {
            throw new McpError('arguments must be an object', -32602);
        }
        foreach ($tool['inputSchema']['required'] ?? [] as $key) {
            if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '' || $args[$key] === []) {
                return self::toolResult("Pflichtangabe fehlt: $key", true);
            }
        }
        try {
            $out = ($tool['handler'])($args);
        } catch (ToolError $e) {
            return self::toolResult($e->getMessage(), true);
        } catch (\Throwable $e) {
            error_log("MCP-Tool $name: " . $e);
            return self::toolResult('Interner Fehler – bitte später erneut versuchen.', true);
        }
        return self::toolResult(is_string($out) ? $out : (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), false);
    }

    private static function toolResult(string $text, bool $isError): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    private static function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
