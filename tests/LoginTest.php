<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\Http\HttpResponse;
use Synthigy\LoginStore;
use Synthigy\MemoryLoginStore;
use Synthigy\Synthigy;
use Synthigy\SynthigyError;
use Synthigy\Tests\Fixtures\FakeTransport;

final class RecordingLoginStore implements LoginStore
{
    /** @var array<string, array{code_verifier: string, nonce: string, return_to: string, created_at: int}> */
    public array $puts = [];

    private MemoryLoginStore $inner;

    public function __construct()
    {
        $this->inner = new MemoryLoginStore();
    }

    public function put(string $state, array $login): void
    {
        $this->puts[$state] = $login;
        $this->inner->put($state, $login);
    }

    public function take(string $state): ?array
    {
        return $this->inner->take($state);
    }
}

final class LoginTest extends TestCase
{
    private const CB = 'http://app/cb';

    private function client(FakeTransport $t, ?LoginStore $store): Client
    {
        return new Client(
            endpoint: 'http://engine:7887',
            token: 'static',
            clientId: 'bff',
            clientSecret: 's3cret',
            transport: $t,
            loginStore: $store,
        );
    }

    /** @param array<string,mixed> $claims */
    private static function jwt(array $claims): string
    {
        return 'e30.' . rtrim(strtr(base64_encode((string)json_encode($claims)), '+/', '-_'), '=') . '.sig';
    }

    /** @return array<string,string> */
    private static function query(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        /** @var array<string,string> $q */
        return $q;
    }

    private static function codeOf(callable $f): string
    {
        try {
            $f();
        } catch (SynthigyError $e) {
            return $e->code;
        }
        return '';
    }

    public function testGuards(): void
    {
        $noSecret = new Client(endpoint: 'http://x', token: 't', transport: new FakeTransport());
        $this->assertSame('LOGIN_REQUIRES_CONFIDENTIAL_CLIENT', self::codeOf(fn () => $noSecret->loginStart(self::CB)));
        $noStore = $this->client(new FakeTransport(), null);
        $this->assertSame('NO_LOGIN_STORE', self::codeOf(fn () => $noStore->loginStart(self::CB)));
        $this->assertSame('NO_LOGIN_STORE', self::codeOf(fn () => $noStore->loginCancel('s')));
    }

    public function testStartBuildsAuthorizeUrl(): void
    {
        $store = new RecordingLoginStore();
        $url = $this->client(new FakeTransport(), $store)
            ->loginStart(self::CB, returnTo: '/movies', publicEndpoint: 'https://id.example.com/')['url'];
        $this->assertStringStartsWith('https://id.example.com/oauth/authorize?', $url);
        $q = self::query($url);
        foreach (['response_type' => 'code', 'client_id' => 'bff', 'redirect_uri' => self::CB,
                  'scope' => 'openid', 'code_challenge_method' => 'S256'] as $k => $v) {
            $this->assertSame($v, $q[$k], $k);
        }
        $pending = $store->puts[$q['state']];
        $this->assertSame($q['nonce'], $pending['nonce']);
        $this->assertSame('/movies', $pending['return_to']);
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $pending['code_verifier'], true)), '+/', '-_'), '='),
            $q['code_challenge'],
        );
    }

    public function testCompleteHappyPathThroughFacade(): void
    {
        $store = new RecordingLoginStore();
        $t = new FakeTransport();
        $t->respondWith(function (string $m, string $u, ?string $body) use ($store): HttpResponse {
            parse_str((string)$body, $form);
            $nonce = '';
            foreach ($store->puts as $p) {
                if ($p['code_verifier'] === ($form['code_verifier'] ?? null)) {
                    $nonce = $p['nonce'];
                }
            }
            return new HttpResponse(200, [], (string)json_encode([
                'access_token' => 'at', 'scope' => 'openid profile',
                'id_token' => self::jwt(['sub' => 'alice', 'xid' => 'x-alice', 'nonce' => $nonce]),
            ]));
        });
        $client = $this->client($t, $store);
        $state = self::query($client->loginStart(self::CB)['url'])['state'];
        $res = $client->loginComplete('the-code', $state, self::CB);

        $this->assertSame(['xid' => 'x-alice', 'name' => 'alice', 'scopes' => ['openid', 'profile']], $res['user']);
        $this->assertSame('at', $res['tokens']['access_token']);
        $this->assertSame('/', $res['return_to']);
        $req = $t->requests[0];
        $this->assertSame('http://engine:7887/oauth/token', $req['url']);
        $this->assertArrayNotHasKey('Authorization', $req['headers'], 'the exchange carries no bearer');
        parse_str((string)$req['body'], $form);
        foreach (['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => self::CB,
                  'client_id' => 'bff', 'client_secret' => 's3cret'] as $k => $v) {
            $this->assertSame($v, $form[$k], $k);
        }
        $this->assertSame('LOGIN_STATE_UNKNOWN', self::codeOf(fn () => $client->loginComplete('the-code', $state, self::CB)));
        $this->assertCount(1, $t->requests, 'a replayed state never reaches the IdP');
    }

    public function testFacadeForwardsLoginStore(): void
    {
        Synthigy::connect(endpoint: 'http://x', token: 't', clientId: 'bff', clientSecret: 's',
            loginStore: new MemoryLoginStore());
        try {
            $state = self::query(Synthigy::loginStart(self::CB, returnTo: '/r')['url'])['state'];
            $this->assertSame(['return_to' => '/r'], Synthigy::loginCancel($state));
        } finally {
            Synthigy::disconnect();
        }
    }

    /** @return array<string, array{int, array<string,mixed>, string}> */
    public static function failures(): array
    {
        return [
            'nonce mismatch' => [200, ['id_token' => self::jwt(['xid' => 'x', 'nonce' => 'wrong'])], 'LOGIN_NONCE_MISMATCH'],
            'non-2xx' => [400, ['error' => 'invalid_grant'], 'LOGIN_EXCHANGE_FAILED'],
            'no id_token' => [200, ['access_token' => 'at'], 'LOGIN_EXCHANGE_FAILED'],
            'no xid' => [200, ['id_token' => self::jwt(['sub' => 'a'])], 'LOGIN_EXCHANGE_FAILED'],
        ];
    }

    /**
     * @param array<string,mixed> $body
     * @dataProvider failures
     */
    public function testCompleteFailures(int $status, array $body, string $code): void
    {
        $t = (new FakeTransport())->respondJson($status, $body);
        $client = $this->client($t, new MemoryLoginStore());
        $state = self::query($client->loginStart(self::CB)['url'])['state'];
        try {
            $client->loginComplete('c', $state, self::CB);
            $this->fail('expected ' . $code);
        } catch (SynthigyError $e) {
            $this->assertSame($code, $e->code);
            if ($status !== 200) {
                $this->assertSame($status, $e->status);
            }
        }
    }

    public function testExpiredLoginIsRefusedEvenWhenTheStoreKeptIt(): void
    {
        $forever = new class implements LoginStore {
            /** @var array<string, array{code_verifier: string, nonce: string, return_to: string, created_at: int}> */
            private array $pending = [];

            public function put(string $state, array $login): void
            {
                $this->pending[$state] = $login;
            }

            public function take(string $state): ?array
            {
                $login = $this->pending[$state] ?? null;
                unset($this->pending[$state]);
                return $login;
            }
        };
        $old = ['code_verifier' => 'v', 'nonce' => 'n', 'return_to' => '/back', 'created_at' => time() - LoginStore::TTL_SECONDS - 1];
        $forever->put('done', $old);
        $forever->put('cancelled', $old);
        $t = new FakeTransport();
        $client = $this->client($t, $forever);

        $this->assertSame('LOGIN_STATE_UNKNOWN', self::codeOf(fn () => $client->loginComplete('the-code', 'done', self::CB)));
        $this->assertNull($client->loginCancel('cancelled'));
        $this->assertCount(0, $t->requests, 'an expired login never reaches the IdP');
    }

    public function testDefaultTtlOutlivesTheServersLoginWindow(): void
    {
        $store = new MemoryLoginStore();
        $store->put('slow', ['code_verifier' => 'v', 'nonce' => 'n', 'return_to' => '/', 'created_at' => time() - 9 * 60]);
        $this->assertNotNull($store->take('slow'), 'a 9-minute login must survive the 8-minute server window');
    }

    public function testCancelIsOneShotAndStoreExpires(): void
    {
        $client = $this->client(new FakeTransport(), new MemoryLoginStore());
        $state = self::query($client->loginStart(self::CB, returnTo: '/back')['url'])['state'];
        $this->assertSame(['return_to' => '/back'], $client->loginCancel($state));
        $this->assertNull($client->loginCancel($state));

        $store = new MemoryLoginStore(ttlSeconds: 1);
        $store->put('s', ['code_verifier' => 'v', 'nonce' => 'n', 'return_to' => '/', 'created_at' => time() - 10]);
        $this->assertNull($store->take('s'));
    }
}
