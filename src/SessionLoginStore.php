<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * LoginStore over $_SESSION, for PHP-FPM and mod_php where /login and the
 * callback run in different processes. Call session_start() before
 * loginStart() and loginComplete(). The session cookie must not be
 * SameSite=Strict: the IdP's redirect back is a cross-site navigation, and
 * a Strict cookie is not sent on it.
 *
 * @phpstan-import-type PendingLogin from LoginStore
 */
final class SessionLoginStore implements LoginStore
{
    public function __construct(
        private readonly int $ttlSeconds = LoginStore::TTL_SECONDS,
        private readonly string $key = 'synthigy_login',
    ) {
    }

    public function put(string $state, array $login): void
    {
        $cutoff = time() - $this->ttlSeconds;
        $pending = array_filter(
            $this->pending(),
            fn (mixed $l): bool => is_array($l) && (int)($l['created_at'] ?? 0) > $cutoff,
        );
        $pending[$state] = $login;
        $_SESSION[$this->key] = $pending;
    }

    public function take(string $state): ?array
    {
        $pending = $this->pending();
        /** @var PendingLogin|null $login */
        $login = $pending[$state] ?? null;
        unset($pending[$state]);
        $_SESSION[$this->key] = $pending;
        if (!is_array($login) || (int)$login['created_at'] <= time() - $this->ttlSeconds) {
            return null;
        }
        return $login;
    }

    /** @return array<string, mixed> */
    private function pending(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new SynthigyError(
                'SessionLoginStore needs an active session: call session_start() before '
                . 'loginStart() and loginComplete().',
                'SESSION_NOT_STARTED',
            );
        }
        $pending = $_SESSION[$this->key] ?? [];
        return is_array($pending) ? $pending : [];
    }
}
