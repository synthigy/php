<?php

declare(strict_types=1);

namespace Synthigy\Auth;

/**
 * Token source for SYNTHIGY_SUPERVISED=1 — a thin handle onto the
 * process-wide SupervisedIO singleton (cache and all), so multiple
 * Clients in one process share one cache instead of each asking the
 * parent independently.
 */
final class SupervisedTokenSource implements TokenSource
{
    public function getToken(?string $audience): string
    {
        return SupervisedIO::instance()->getToken($audience);
    }

    public function clear(): void
    {
        SupervisedIO::instance()->clear();
    }
}
