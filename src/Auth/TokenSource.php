<?php

declare(strict_types=1);

namespace Synthigy\Auth;

use Synthigy\SynthigyError;

/**
 * Anything that can produce and invalidate a bearer token. TokenManager,
 * StaticTokenSource, EnvTokenSource and SupervisedTokenSource all implement
 * this so Client's 401-clear-and-retry-once path works identically
 * regardless of which source is installed.
 */
interface TokenSource
{
    /** @throws SynthigyError */
    public function getToken(?string $audience): string;

    public function clear(): void;
}
