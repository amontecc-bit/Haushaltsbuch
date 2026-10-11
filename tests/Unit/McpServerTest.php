<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mcp\McpServer;
use App\Services\Mcp\ToolError;
use PHPUnit\Framework\TestCase;

final class McpServerTest extends TestCase
{
    private function server(): McpServer
    {
        return new McpServer([
            'echo' => [
                'title' => 'Echo', 'description' => 'gibt den Text zurück',
                'inputSchema' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']],
                'annotations' => ['readOnlyHint' => true],
                'handler' => fn (array $a) => ['text' => $a['text']],
            ],
            'leer' => [
                'title' => 'Leer', 'description' => 'ohne Parameter',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'handler' => fn (array $a) => 'ok',
            ],
            'fehler' => [
                'title' => 'Fehler', 'description' => 'wirft',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'handler' => fn (array $a) => throw new ToolError('Gibt es nicht.'),
            ],
            'absturz' => [
                'title' => 'Absturz', 'description' => 'interner Fehler',
                'inputSchema' => ['type' => 'object', 'properties' => []],
                'handler' => fn (array $a) => throw new \RuntimeException('SQLSTATE geheim'),
            ],
        ], 'Hinweise');
    }

    private function call(array $msg): ?array
    {
        return $this->server()->handle(['jsonrpc' => '2.0', 'id' => 1] + $msg);
    }

    public function testInitializeNegotiatesVersion(): void
    {
        $r = $this->call(['method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18']])['result'];
        $this->assertSame('2025-06-18', $r['protocolVersion']);
        $this->assertSame('Hinweise', $r['instructions']);
        $this->assertArrayHasKey('tools', $r['capabilities']);

        $r = $this->call(['method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])['result'];
        $this->assertSame(McpServer::VERSIONS[0], $r['protocolVersion']);
    }

    public function testNotificationsAndClientResponsesGetNoAnswer(): void
    {
        $this->assertNull($this->server()->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        $this->assertNull($this->server()->handle(['jsonrpc' => '2.0', 'id' => 5, 'result' => []]));
    }

    public function testPingReturnsEmptyObject(): void
    {
        $this->assertSame('{}', json_encode($this->call(['method' => 'ping'])['result']));
    }

    public function testToolsListUsesObjectsForEmptyProperties(): void
    {
        $tools = $this->call(['method' => 'tools/list'])['result']['tools'];
        $this->assertSame(['echo', 'leer', 'fehler', 'absturz'], array_column($tools, 'name'));
        $this->assertStringContainsString('"properties":{}', json_encode($tools[1]['inputSchema']));
        $this->assertSame(['title' => 'Echo', 'readOnlyHint' => true], $tools[0]['annotations']);
        $this->assertArrayNotHasKey('handler', $tools[0]);
    }

    public function testToolCallReturnsJsonText(): void
    {
        $r = $this->call(['method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => ['text' => 'Grüße']]])['result'];
        $this->assertFalse($r['isError']);
        $this->assertSame('{"text":"Grüße"}', $r['content'][0]['text']);

        $r = $this->call(['method' => 'tools/call', 'params' => ['name' => 'leer']])['result'];
        $this->assertSame('ok', $r['content'][0]['text']);
    }

    public function testMissingRequiredArgumentIsToolError(): void
    {
        $r = $this->call(['method' => 'tools/call', 'params' => ['name' => 'echo', 'arguments' => []]])['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('Pflichtangabe fehlt: text', $r['content'][0]['text']);
    }

    public function testToolErrorsAreReportedButInternalsHidden(): void
    {
        $r = $this->call(['method' => 'tools/call', 'params' => ['name' => 'fehler']])['result'];
        $this->assertTrue($r['isError']);
        $this->assertSame('Gibt es nicht.', $r['content'][0]['text']);

        $log = ini_set('error_log', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null');
        $r = $this->call(['method' => 'tools/call', 'params' => ['name' => 'absturz']])['result'];
        ini_set('error_log', (string) $log);
        $this->assertTrue($r['isError']);
        $this->assertStringNotContainsString('SQLSTATE', $r['content'][0]['text']);
    }

    public function testProtocolErrors(): void
    {
        $this->assertSame(-32602, $this->call(['method' => 'tools/call', 'params' => ['name' => 'gibtsnicht']])['error']['code']);
        $this->assertSame(-32601, $this->call(['method' => 'resources/list'])['error']['code']);
        $this->assertSame(-32600, $this->server()->handle(['id' => 1, 'method' => 'ping'])['error']['code']);
        $this->assertSame(-32600, $this->server()->handle('quatsch')['error']['code']);
    }

    public function testBatch(): void
    {
        $out = $this->server()->handle([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ]);
        $this->assertSame([1, 2], array_column($out, 'id'));
        $this->assertNull($this->server()->handle([['jsonrpc' => '2.0', 'method' => 'notifications/initialized']]));
    }
}
