<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Auth\TokenManager;
use Synthigy\Client;
use Synthigy\FileTokenStore;
use Synthigy\Tests\Fixtures\FakeTransport;

final class TokenStoreTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthigy-token-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            unlink($f);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }
    }

    private function manager(FakeTransport $t, string $secret = 'secret', ?string $dir = null): TokenManager
    {
        return new TokenManager($t, 'https://x.test/oauth/token', 'cid', $secret, null, new FileTokenStore($dir ?? $this->dir));
    }

    public function testSecondManagerReusesStoredTokenWithoutMinting(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 900]);

        self::assertSame('tok-1', $this->manager($t)->getToken('https://synthigy.com'));
        self::assertSame('tok-1', $this->manager($t)->getToken('https://synthigy.com'));
        self::assertCount(1, $t->requests);
    }

    public function testStoredTokensAreKeyedByAudienceAndCredentials(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-a', 'expires_in' => 900]);
        $t->respondJson(200, ['access_token' => 'tok-b', 'expires_in' => 900]);
        $t->respondJson(200, ['access_token' => 'tok-c', 'expires_in' => 900]);

        self::assertSame('tok-a', $this->manager($t)->getToken('https://synthigy.com'));
        self::assertSame('tok-b', $this->manager($t)->getToken('https://other.test'));
        self::assertSame('tok-c', $this->manager($t, 'rotated')->getToken('https://synthigy.com'));
        self::assertCount(3, $t->requests);
    }

    public function testTokenInsideExpiryBufferIsRemintedAndReplaced(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-short', 'expires_in' => 20]);
        $t->respondJson(200, ['access_token' => 'tok-new', 'expires_in' => 900]);

        self::assertSame('tok-short', $this->manager($t)->getToken(null));
        self::assertSame('tok-new', $this->manager($t)->getToken(null));
        self::assertSame('tok-new', $this->manager($t)->getToken(null));
        self::assertCount(2, $t->requests);
    }

    public function testClearDropsTheStoredTokenForEveryProcess(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'revoked', 'expires_in' => 900]);
        $t->respondJson(200, ['access_token' => 'fresh', 'expires_in' => 900]);

        $first = $this->manager($t);
        self::assertSame('revoked', $first->getToken(null));
        $first->clear();

        self::assertSame('fresh', $this->manager($t)->getToken(null));
        self::assertCount(2, $t->requests);
    }

    public function testClientsShareTokensThroughTheStoreLikeTwoRequests(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok', 'expires_in' => 900]);
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);

        foreach ([1, 2] as $_) {
            (new Client(endpoint: 'https://x.test', clientId: 'cid', clientSecret: 'secret',
                transport: $t, tokenStore: new FileTokenStore($this->dir)))->search('movie');
        }

        self::assertCount(3, $t->requests, 'one mint, then two /data calls');
        self::assertSame('Bearer tok', $t->requests[2]['headers']['Authorization']);
    }

    public function testTokenStoreFalseKeepsTokensInProcess(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 900]);
        $t->respondJson(200, ['access_token' => 'tok-2', 'expires_in' => 900]);

        foreach (['tok-1', 'tok-2'] as $expected) {
            $c = new Client(endpoint: 'https://x.test', clientId: 'cid', clientSecret: 'secret',
                transport: $t, tokenStore: false);
            self::assertSame($expected, $c->token());
        }
        self::assertCount(2, $t->requests);
    }

    public function testFilesArePrivateAndHoldNoSecret(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok', 'expires_in' => 900]);
        $this->manager($t)->getToken(null);

        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [];
        self::assertCount(1, $files);
        self::assertSame(0700, fileperms($this->dir) & 0777);
        self::assertSame(0600, fileperms($files[0]) & 0777);
        self::assertStringNotContainsString('secret', basename($files[0]) . file_get_contents($files[0]));
    }

    public function testCorruptFileIsIgnored(): void
    {
        $store = new FileTokenStore($this->dir);
        $store->put('k', 'tok', microtime(true) + 900);
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [];
        file_put_contents($files[0], '{not json');

        self::assertNull($store->get('k'));
    }

    public function testUnusableDirectoryDegradesToMintingWithoutErrors(): void
    {
        $file = $this->dir . '.file';
        file_put_contents($file, 'not a directory');
        try {
            $t = new FakeTransport();
            $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 900]);
            $t->respondJson(200, ['access_token' => 'tok-2', 'expires_in' => 900]);

            self::assertSame('tok-1', $this->manager($t, dir: $file)->getToken(null));
            self::assertSame('tok-2', $this->manager($t, dir: $file)->getToken(null));
        } finally {
            unlink($file);
        }
    }

    public function testWritableDirectoryOwnedByAnotherUserIsNotUsed(): void
    {
        $shared = sys_get_temp_dir();
        if (!function_exists('posix_geteuid') || fileowner($shared) === posix_geteuid() || !is_writable($shared)) {
            self::markTestSkipped('needs posix and a writable temp dir owned by another user');
        }
        $store = new FileTokenStore($shared);
        try {
            $store->put('owner-check', 'tok', microtime(true) + 900);
            self::assertNull($store->get('owner-check'));
            self::assertFileDoesNotExist($shared . DIRECTORY_SEPARATOR . 'owner-check.json');
        } finally {
            @unlink($shared . DIRECTORY_SEPARATOR . 'owner-check.json');
        }
    }

    public function testDefaultDirectoryIsPerUserUnderTemp(): void
    {
        $dir = FileTokenStore::defaultDirectory();
        self::assertStringStartsWith(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'synthigy-tokens-', $dir);
        if (function_exists('posix_geteuid')) {
            self::assertStringEndsWith('-' . posix_geteuid(), $dir);
        }
    }

    public function testAStoreThatThrowsNeverFailsARequest(): void
    {
        $broken = new class implements \Synthigy\TokenStore {
            public function get(string $key): ?array { throw new \RuntimeException('redis down'); }
            public function put(string $key, string $token, float $expiresAt): void { throw new \RuntimeException('redis down'); }
            public function delete(string $key): void { throw new \RuntimeException('redis down'); }
        };
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'tok', 'expires_in' => 900]);

        $m = new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret', null, $broken);
        self::assertSame('tok', $m->getToken(null));
        $m->clear();
        self::assertCount(1, $t->requests);
    }

    public function testAMalformedStoredEntryIsIgnored(): void
    {
        $odd = new class implements \Synthigy\TokenStore {
            // @phpstan-ignore return.type, return.unusedType, return.unusedType
            public function get(string $key): ?array { return ['token' => 42]; }
            public function put(string $key, string $token, float $expiresAt): void {}
            public function delete(string $key): void {}
        };
        $t = new FakeTransport();
        $t->respondJson(200, ['access_token' => 'minted', 'expires_in' => 900]);

        self::assertSame('minted', (new TokenManager($t, 'https://x.test/oauth/token', 'cid', 'secret', null, $odd))->getToken(null));
    }
}
