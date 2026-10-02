<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\SessionLoginStore;
use Synthigy\SynthigyError;

final class SessionLoginStoreTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
    }

    private static function startSession(): void
    {
        ini_set('session.use_cookies', '0');
        ini_set('session.cache_limiter', '');
        session_save_path(sys_get_temp_dir());
        session_id('synthigytest' . bin2hex(random_bytes(8)));
        self::assertTrue(session_start());
    }

    /** @return array{code_verifier: string, nonce: string, return_to: string, created_at: int} */
    private static function login(int $age = 0): array
    {
        return ['code_verifier' => 'v', 'nonce' => 'n', 'return_to' => '/back', 'created_at' => time() - $age];
    }

    public function testRequiresAnActiveSession(): void
    {
        try {
            (new SessionLoginStore())->put('s', self::login());
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame(['SESSION_NOT_STARTED', 'validation', false], [$e->code, $e->category, $e->retryable]);
        }
    }

    public function testTakeIsOneShot(): void
    {
        self::startSession();
        $store = new SessionLoginStore();
        $login = self::login();
        $store->put('s', $login);

        self::assertSame($login, $store->take('s'));
        self::assertNull($store->take('s'));
        self::assertNull($store->take('never-put'));
    }

    public function testExpiredLoginsAreRefusedAndPruned(): void
    {
        self::startSession();
        $store = new SessionLoginStore(ttlSeconds: 60);
        $store->put('old', self::login(age: 120));
        self::assertNull($store->take('old'));

        $store->put('stale', self::login(age: 120));
        $store->put('fresh', self::login());
        self::assertSame(['fresh'], array_keys($_SESSION['synthigy_login']));
    }

    public function testDefaultTtlOutlivesTheServersLoginWindow(): void
    {
        self::startSession();
        $store = new SessionLoginStore();
        $login = self::login(age: 9 * 60);
        $store->put('slow', $login);
        self::assertSame($login, $store->take('slow'));
    }

    public function testCustomSessionKey(): void
    {
        self::startSession();
        (new SessionLoginStore(key: 'my_logins'))->put('s', self::login());
        self::assertArrayHasKey('s', $_SESSION['my_logins']);
        self::assertArrayNotHasKey('synthigy_login', $_SESSION);
    }
}
