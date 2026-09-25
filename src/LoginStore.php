<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Holds in-flight browser logins between loginStart() and the callback.
 * take() is one-shot: it returns the login once and removes it, or null
 * when the state is unknown or expired. Back it with whatever already holds
 * your sessions ($_SESSION, Redis, a table).
 *
 * @phpstan-type PendingLogin array{code_verifier: string, nonce: string, return_to: string, created_at: int}
 */
interface LoginStore
{
    /** @param PendingLogin $login */
    public function put(string $state, array $login): void;

    /** @return PendingLogin|null */
    public function take(string $state): ?array;
}
