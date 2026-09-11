<?php

declare(strict_types=1);

namespace Synthigy\Auth;

use Synthigy\Http\HttpTransport;
use Synthigy\SynthigyError;

/**
 * OAuth client-credentials grant against the server's /oauth/token
 * endpoint. Tokens are cached per audience and refreshed 30s before
 * expiry. PHP-FPM is single-threaded per request, so there is no
 * concurrent-refresh race to single-flight within one request the way the
 * JS/Go/Python SDKs must across threads/tasks — the cache still saves a
 * round trip across calls within the same request/process.
 */
final class TokenManager implements TokenSource
{
    private const EXPIRY_BUFFER_SECONDS = 30;

    /** @var array<string, array{token: string, expiresAt: float}> */
    private array $tokens = [];

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $scope = null,
    ) {
    }

    public function getToken(?string $audience): string
    {
        $key = $audience ?? '';
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && microtime(true) < $cached['expiresAt'] - self::EXPIRY_BUFFER_SECONDS) {
            return $cached['token'];
        }

        $form = [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ];
        if ($this->scope) {
            $form['scope'] = $this->scope;
        }
        if ($audience) {
            $form['audience'] = $audience;
        }

        $resp = $this->transport->request(
            'POST',
            $this->tokenUrl,
            http_build_query($form, '', '&'),
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            null,
        );

        if ($resp->status < 200 || $resp->status >= 300) {
            throw new SynthigyError(
                "Token request failed ({$resp->status}): {$resp->body}",
                'UNAUTHORIZED',
                status: $resp->status,
            );
        }

        $decoded = json_decode($resp->body, true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new SynthigyError('Invalid token response', 'INTERNAL_ERROR');
        }

        $expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (float)$decoded['expires_in'] : 3600.0;
        $this->tokens[$key] = [
            'token' => (string)$decoded['access_token'],
            'expiresAt' => microtime(true) + $expiresIn,
        ];
        return $this->tokens[$key]['token'];
    }

    public function clear(): void
    {
        $this->tokens = [];
    }
}
