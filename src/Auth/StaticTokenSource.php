<?php

declare(strict_types=1);

namespace Synthigy\Auth;

/**
 * Wraps a fixed bearer token. An empty string is a deliberate dev-mode
 * value — Client sends no Authorization header at all for it (not
 * `Bearer `), matching an authless/dev server.
 */
final class StaticTokenSource implements TokenSource
{
    public function __construct(private readonly string $token)
    {
    }

    public function getToken(?string $audience): string
    {
        return $this->token;
    }

    public function clear(): void
    {
        // Nothing to invalidate — the caller owns the token's lifecycle.
    }
}
