<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Keeps client-credentials tokens between requests, so each PHP request reuses a token instead of minting one.
 *
 * Keys are opaque, already derived from the endpoint, client, secret, scope and audience.
 *
 * @phpstan-type StoredToken array{token: string, expires_at: float}
 */
interface TokenStore
{
    /** @return StoredToken|null */
    public function get(string $key): ?array;

    public function put(string $key, string $token, float $expiresAt): void;

    public function delete(string $key): void;
}
