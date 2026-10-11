<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\OAuthService;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase
{
    public function testPkceS256(): void
    {
        // Beispiel aus RFC 7636, Anhang B
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $this->assertTrue(OAuthService::verifyPkce($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        $this->assertFalse(OAuthService::verifyPkce($verifier, 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cN'));
        $this->assertFalse(OAuthService::verifyPkce('zu-kurz', 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM'));
        $this->assertFalse(OAuthService::verifyPkce($verifier, ''));
    }

    public function testRedirectOnlyToAllowedHostsOrLocalhost(): void
    {
        $hosts = ['claude.ai', 'claude.com'];
        $this->assertTrue(OAuthService::redirectAllowed('https://claude.ai/api/mcp/auth_callback', $hosts));
        $this->assertTrue(OAuthService::redirectAllowed('https://www.claude.com/cb', $hosts));
        $this->assertTrue(OAuthService::redirectAllowed('http://localhost:6274/oauth/callback', $hosts));
        $this->assertTrue(OAuthService::redirectAllowed('http://127.0.0.1:33418/callback', $hosts));

        $this->assertFalse(OAuthService::redirectAllowed('http://claude.ai/cb', $hosts), 'nur https');
        $this->assertFalse(OAuthService::redirectAllowed('https://evil-claude.ai/cb', $hosts));
        $this->assertFalse(OAuthService::redirectAllowed('https://claude.ai.evil.com/cb', $hosts));
        $this->assertFalse(OAuthService::redirectAllowed('https://user:pw@claude.ai/cb', $hosts));
        $this->assertFalse(OAuthService::redirectAllowed('https://claude.ai/cb#frag', $hosts));
        $this->assertFalse(OAuthService::redirectAllowed('javascript:alert(1)', $hosts));
        $this->assertFalse(OAuthService::redirectAllowed('ftp://localhost/x', $hosts));
    }

    public function testAppendQuery(): void
    {
        $this->assertSame('https://a.de/cb?code=x&state=a%20b', OAuthService::appendQuery('https://a.de/cb', ['code' => 'x', 'state' => 'a b']));
        $this->assertSame('https://a.de/cb?v=1&code=x', OAuthService::appendQuery('https://a.de/cb?v=1', ['code' => 'x']));
    }

    public function testBearerAndBasicFromServerVars(): void
    {
        $this->assertSame('abc123', OAuthService::bearer(['HTTP_AUTHORIZATION' => 'Bearer abc123']));
        $this->assertSame('abc123', OAuthService::bearer(['REDIRECT_HTTP_AUTHORIZATION' => 'bearer abc123']));
        $this->assertNull(OAuthService::bearer(['HTTP_AUTHORIZATION' => 'Basic abc123']));
        $this->assertSame(['hb_1', 'geheim:x'], OAuthService::basicCredentials(['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('hb_1:geheim%3Ax')]));
        $this->assertNull(OAuthService::basicCredentials(['HTTP_AUTHORIZATION' => 'Basic !!!']));
    }

    public function testResourceMatches(): void
    {
        $mcp = 'https://haushalt.example.de/mcp';
        $this->assertTrue(OAuthService::resourceMatches('', $mcp));
        $this->assertTrue(OAuthService::resourceMatches('https://Haushalt.Example.de/mcp/', $mcp));
        $this->assertFalse(OAuthService::resourceMatches('https://haushalt.example.de/andere', $mcp));
        $this->assertFalse(OAuthService::resourceMatches('https://anderer.de/mcp', $mcp));
    }

    public function testTokensAreRandomAndHashed(): void
    {
        $a = OAuthService::token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertNotSame($a, OAuthService::token());
        $this->assertSame(hash('sha256', $a), OAuthService::hash($a));
    }
}
