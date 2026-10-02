<?php

declare(strict_types=1);

namespace Synthigy\Auth;

use Synthigy\Http\HttpTransport;
use Synthigy\SynthigyError;
use Synthigy\TokenStore;

/**
 * OAuth client-credentials grant against /oauth/token, cached per audience and refreshed 30s before expiry.
 *
 * The in-memory cache lives as long as the PHP process; a TokenStore carries tokens across requests.
 */
final class TokenManager implements TokenSource
{
    private const EXPIRY_BUFFER_SECONDS = 30;

    /** RFC 6749 §5.2 / RFC 8707 refusals with their own code; any other refusal is UNAUTHORIZED. */
    private const REFUSAL_CODES = [
        'invalid_client' => 'INVALID_CLIENT',
        'invalid_scope' => 'INVALID_SCOPE',
        'invalid_target' => 'INVALID_AUDIENCE',
    ];

    /** @var array<string, array{token: string, expiresAt: float}> */
    private array $tokens = [];

    public function __construct(
        private readonly HttpTransport $transport,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $scope = null,
        private readonly ?TokenStore $store = null,
    ) {
    }

    public function getToken(?string $audience): string
    {
        $key = $audience ?? '';
        $cached = $this->tokens[$key] ?? null;
        if ($cached !== null && self::fresh($cached['expiresAt'])) {
            return $cached['token'];
        }

        $stored = $this->viaStore(fn (TokenStore $s) => $s->get($this->storeKey($key)));
        if (is_array($stored) && is_string($stored['token'] ?? null)
            && is_numeric($stored['expires_at'] ?? null) && self::fresh((float)$stored['expires_at'])) {
            $this->tokens[$key] = ['token' => $stored['token'], 'expiresAt' => (float)$stored['expires_at']];
            return $stored['token'];
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
            throw self::refusal($resp->status, $resp->body);
        }

        $decoded = json_decode($resp->body, true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            throw new SynthigyError('Invalid token response', 'INTERNAL_ERROR');
        }

        $expiresIn = is_numeric($decoded['expires_in'] ?? null) ? (float)$decoded['expires_in'] : 3600.0;
        $token = (string)$decoded['access_token'];
        $expiresAt = microtime(true) + $expiresIn;
        $this->tokens[$key] = ['token' => $token, 'expiresAt' => $expiresAt];
        $this->viaStore(fn (TokenStore $s) => $s->put($this->storeKey($key), $token, $expiresAt));
        return $token;
    }

    /** Forgets every token this manager handed out, in memory and in the store, so the next call mints. */
    public function clear(): void
    {
        foreach (array_keys($this->tokens) as $key) {
            $this->viaStore(fn (TokenStore $s) => $s->delete($this->storeKey((string)$key)));
        }
        $this->tokens = [];
    }

    /** A store is a cache: whatever it throws, the SDK carries on as if it held nothing. */
    private function viaStore(callable $op): mixed
    {
        if ($this->store === null) {
            return null;
        }
        try {
            return $op($this->store);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function refusal(int $status, string $body): SynthigyError
    {
        $oauth = json_decode($body, true);
        $error = is_array($oauth) && is_string($oauth['error'] ?? null) ? $oauth['error'] : null;
        if ($error === null) {
            return new SynthigyError("Token request failed ({$status}): {$body}", 'UNAUTHORIZED', status: $status);
        }
        $description = is_string($oauth['error_description'] ?? null) && $oauth['error_description'] !== ''
            ? " — {$oauth['error_description']}" : '';
        $code = self::REFUSAL_CODES[$error] ?? 'UNAUTHORIZED';
        return new SynthigyError(
            "Token request refused ({$status}): {$error}{$description}",
            $code,
            $oauth,
            hint: $code === 'INVALID_CLIENT' ? 'check the client id and secret, and that the client is active' : null,
            status: $status,
        );
    }

    private static function fresh(float $expiresAt): bool
    {
        return microtime(true) < $expiresAt - self::EXPIRY_BUFFER_SECONDS;
    }

    private function storeKey(string $audience): string
    {
        return hash_hmac(
            'sha256',
            implode("\n", [$this->tokenUrl, $this->clientId, $this->scope ?? '', $audience]),
            $this->clientSecret,
        );
    }
}
