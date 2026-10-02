<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * In-memory LoginStore for ONE long-lived process — under PHP-FPM or
 * mod_php every request is a fresh process, so /login and the callback
 * never share it and every login fails LOGIN_STATE_UNKNOWN. There, store
 * logins in $_SESSION or Redis. Useful for tests and a single-process
 * server (RoadRunner, Swoole, `php -S` scripts that call both halves).
 *
 * @phpstan-import-type PendingLogin from LoginStore
 */
final class MemoryLoginStore implements LoginStore
{
    /** @var array<string, PendingLogin> */
    private array $pending = [];

    public function __construct(private readonly int $ttlSeconds = LoginStore::TTL_SECONDS)
    {
    }

    public function put(string $state, array $login): void
    {
        $cutoff = time() - $this->ttlSeconds;
        $this->pending = array_filter($this->pending, fn (array $l): bool => (int)$l['created_at'] > $cutoff);
        $this->pending[$state] = $login;
    }

    public function take(string $state): ?array
    {
        $login = $this->pending[$state] ?? null;
        unset($this->pending[$state]);
        if ($login === null || (int)$login['created_at'] <= time() - $this->ttlSeconds) {
            return null;
        }
        return $login;
    }
}
