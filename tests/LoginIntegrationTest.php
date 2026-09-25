<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\MemoryLoginStore;
use Synthigy\SynthigyError;

/**
 * Live browser login, the browser played headlessly with curl.
 *
 * Env: SYNTHIGY_TEST_LOGIN_CLIENT_ID/_SECRET (confidential client allowing
 * authorization_code, SYNTHIGY_TEST_LOGIN_REDIRECT registered) and
 * SYNTHIGY_TEST_LOGIN_USER/_PASSWORD (a password user).
 */
#[Group('live')]
final class LoginIntegrationTest extends TestCase
{
    private static function env(string $name): string
    {
        return getenv($name) ?: '';
    }

    private static function endpoint(): string
    {
        return self::env('SYNTHIGY_TEST_ENDPOINT') ?: 'http://localhost:7887';
    }

    private static function redirect(): string
    {
        return self::env('SYNTHIGY_TEST_LOGIN_REDIRECT') ?: 'http://127.0.0.1:9/py-sdk/callback';
    }

    /** @return array<string,string> query of the response's Location header */
    private static function location(string $url, ?string $post = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        $raw = (string)curl_exec($ch);
        curl_close($ch);
        preg_match('/^location:\s*(\S+)/mi', $raw, $m);
        parse_str((string)parse_url($m[1] ?? '', PHP_URL_QUERY), $q);
        /** @var array<string,string> $q */
        return $q;
    }

    /** @return array<string,string> the callback's query */
    private static function browserLogin(string $authorizeUrl): array
    {
        $page = self::location($authorizeUrl);
        return self::location(self::endpoint() . '/oauth/login', http_build_query([
            'username' => self::env('SYNTHIGY_TEST_LOGIN_USER'),
            'password' => self::env('SYNTHIGY_TEST_LOGIN_PASSWORD'),
            'state' => $page['state'] ?? '',
        ]));
    }

    private static function client(string $secret, MemoryLoginStore $store): Client
    {
        return new Client(endpoint: self::endpoint(), clientId: self::env('SYNTHIGY_TEST_LOGIN_CLIENT_ID'),
            clientSecret: $secret, loginStore: $store);
    }

    public function testCodeFlowReturnsTheSameUserTwiceAndRejectsAWrongSecret(): void
    {
        foreach (['CLIENT_ID', 'CLIENT_SECRET', 'USER', 'PASSWORD'] as $k) {
            if (self::env('SYNTHIGY_TEST_LOGIN_' . $k) === '') {
                self::markTestSkipped('set SYNTHIGY_TEST_LOGIN_CLIENT_ID/SECRET/USER/PASSWORD');
            }
        }
        $store = new MemoryLoginStore();
        $client = self::client(self::env('SYNTHIGY_TEST_LOGIN_CLIENT_SECRET'), $store);
        $xids = [];
        for ($i = 0; $i < 2; $i++) {
            $cb = self::browserLogin($client->loginStart(self::redirect(), returnTo: '/after', scope: 'openid profile')['url']);
            $res = $client->loginComplete($cb['code'] ?? '', $cb['state'] ?? '', self::redirect());
            $this->assertSame(self::env('SYNTHIGY_TEST_LOGIN_USER'), $res['user']['name']);
            $this->assertContains('openid', $res['user']['scopes']);
            $this->assertSame('/after', $res['return_to']);
            $this->assertNotEmpty($res['tokens']['access_token']);
            $xids[] = $res['user']['xid'];
        }
        $this->assertSame($xids[0], $xids[1]);

        $cb = self::browserLogin($client->loginStart(self::redirect())['url']);
        try {
            self::client('wrong', $store)->loginComplete($cb['code'] ?? '', $cb['state'] ?? '', self::redirect());
            $this->fail('wrong secret accepted');
        } catch (SynthigyError $e) {
            $this->assertSame('LOGIN_EXCHANGE_FAILED', $e->code);
            $this->assertContains($e->status, [400, 401]);
        }
    }
}
