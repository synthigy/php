<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Auth\StaticTokenSource;
use Synthigy\Auth\TokenManager;
use Synthigy\SynthigyError;
use Synthigy\Tests\Fixtures\FakeTransport;

final class AuthTest extends TestCase
{
    public function testMintsAndCachesToken(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 3600]);
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret');

        self::assertSame('tok-1', $tm->getToken(null));
        self::assertSame('tok-1', $tm->getToken(null)); // cached — no 2nd request

        self::assertCount(1, $t->requests);
        $form = [];
        parse_str((string)$t->requests[0]['body'], $form);
        self::assertSame('client_credentials', $form['grant_type']);
        self::assertSame('cid', $form['client_id']);
        self::assertSame('secret', $form['client_secret']);
        self::assertArrayNotHasKey('audience', $form);
    }

    public function testTokenPerAudienceCachedSeparately(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-default', 'expires_in' => 3600]);
        $t->respondJson(200, ['access_token' => 'tok-aud', 'expires_in' => 3600]);
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret');

        self::assertSame('tok-default', $tm->getToken(null));
        self::assertSame('tok-aud', $tm->getToken('https://other.test'));
        self::assertSame('tok-default', $tm->getToken(null)); // still cached

        self::assertCount(2, $t->requests);
        $form = [];
        parse_str((string)$t->requests[1]['body'], $form);
        self::assertSame('https://other.test', $form['audience']);
    }

    public function testClearForcesRemint(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 3600]);
        $t->respondJson(200, ['access_token' => 'tok-2', 'expires_in' => 3600]);
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret');

        self::assertSame('tok-1', $tm->getToken(null));
        $tm->clear();
        self::assertSame('tok-2', $tm->getToken(null));
        self::assertCount(2, $t->requests);
    }

    public function testExpiredTokenIsRemintedWithoutExplicitClear(): void
    {
        $t = new FakeTransport();
        // expires_in: 20s is inside the 30s pre-expiry buffer, so the very
        // next getToken() call must treat it as already-expired.
        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 20]);
        $t->respondJson(200, ['access_token' => 'tok-2', 'expires_in' => 3600]);
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret');

        self::assertSame('tok-1', $tm->getToken(null));
        self::assertSame('tok-2', $tm->getToken(null));
        self::assertCount(2, $t->requests);
    }

    public function testNonSuccessStatusRaisesUnauthorized(): void
    {
        $t = new FakeTransport();
        $t->respond(new \Synthigy\Http\HttpResponse(400, [], '{"error":"invalid_client"}'));
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'bad', 'creds');

        try {
            $tm->getToken(null);
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame('UNAUTHORIZED', $e->code);
        }
    }

    public function testMissingAccessTokenInResponseRaisesInternal(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['not_a_token_response' => true]);
        $tm = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret');

        $this->expectException(SynthigyError::class);
        $tm->getToken(null);
    }

    public function testStaticTokenSourceReturnsFixedTokenAndClearIsNoop(): void
    {
        $s = new StaticTokenSource('fixed');
        self::assertSame('fixed', $s->getToken(null));
        $s->clear();
        self::assertSame('fixed', $s->getToken('any-audience'));
    }
}
