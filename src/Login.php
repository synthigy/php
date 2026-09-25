<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Browser login — OIDC authorization code + PKCE for a confidential server.
 * Mixed into Client. The SDK owns the protocol; sessions, cookies and
 * redirects stay the app's.
 */
trait Login
{
    /**
     * Begin a person's login; redirect the browser to the returned 'url'.
     *
     * @return array{url: string}
     */
    public function loginStart(string $redirectUri, string $returnTo = '/', string $scope = 'openid', ?string $publicEndpoint = null): array
    {
        $store = $this->requireLogin();
        if ($redirectUri === '') {
            throw new SynthigyError(
                'loginStart needs $redirectUri — the URL on YOUR server the IdP sends '
                . 'the browser back to, registered on this OAuth client.',
                'LOGIN_REQUIRES_CONFIDENTIAL_CLIENT',
            );
        }
        $state = self::b64url(random_bytes(24));
        $nonce = self::b64url(random_bytes(24));
        $verifier = self::b64url(random_bytes(32));
        // stored BEFORE the URL is handed out — a fast browser would come back to nothing
        $store->put($state, [
            'code_verifier' => $verifier,
            'nonce' => $nonce,
            'return_to' => $returnTo,
            'created_at' => time(),
        ]);
        $base = $publicEndpoint !== null && $publicEndpoint !== '' ? rtrim($publicEndpoint, '/') : $this->endpoint;
        return ['url' => $base . '/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986)];
    }

    /**
     * Exchange the callback's code for tokens and the user behind them.
     *
     * @return array{user: array{xid: string, name: ?string, scopes: list<string>}, tokens: array<string,mixed>, return_to: string}
     * @throws SynthigyError LOGIN_STATE_UNKNOWN, LOGIN_NONCE_MISMATCH, LOGIN_EXCHANGE_FAILED
     */
    public function loginComplete(string $code, string $state, string $redirectUri): array
    {
        $store = $this->requireLogin();
        $pending = $state !== '' ? $store->take($state) : null;
        if ($pending === null) {
            throw new SynthigyError(
                'unknown or expired login state: the store never saw it, it was already '
                . 'consumed, or it timed out. A callback served by a different process '
                . 'than /login does this (MemoryLoginStore under PHP-FPM always does).',
                'LOGIN_STATE_UNKNOWN',
            );
        }
        // never authedRequest — its 401 retry would replay a burned one-shot code
        $resp = $this->transport->request('POST', $this->endpoint . '/oauth/token', http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code_verifier' => $pending['code_verifier'],
        ], '', '&'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], $this->defaultTimeoutSeconds);
        if ($resp->status < 200 || $resp->status >= 300) {
            throw new SynthigyError(
                "authorization-code exchange failed ({$resp->status})",
                'LOGIN_EXCHANGE_FAILED',
                $resp->body,
                status: $resp->status,
            );
        }
        $tokens = json_decode($resp->body, true);
        if (!is_array($tokens)) {
            throw new SynthigyError('token response is not JSON', 'LOGIN_EXCHANGE_FAILED');
        }
        /** @var array<string,mixed> $tokens */
        $claims = self::jwtPayload($tokens['id_token'] ?? null);
        if ($claims === null) {
            throw new SynthigyError(
                'token response carried no readable id_token — request the openid scope, '
                . 'which is what names the user.',
                'LOGIN_EXCHANGE_FAILED',
            );
        }
        $xid = $claims['xid'] ?? null;
        if (!is_string($xid) || $xid === '') {
            throw new SynthigyError(
                "id_token carries no xid claim — without it the user can't be named in "
                . 'actingAs, and calls would silently run as this service.',
                'LOGIN_EXCHANGE_FAILED',
            );
        }
        if (($claims['nonce'] ?? null) !== $pending['nonce']) {
            throw new SynthigyError('id_token nonce does not match the one this login started with', 'LOGIN_NONCE_MISMATCH');
        }
        $scope = $tokens['scope'] ?? $claims['scope'] ?? '';
        $sub = $claims['sub'] ?? null;
        return [
            'user' => [
                'xid' => $xid,
                'name' => is_string($sub) ? $sub : null,
                'scopes' => array_values(array_filter(explode(' ', is_string($scope) ? $scope : ''), fn (string $s): bool => $s !== '')),
            ],
            'tokens' => $tokens,
            'return_to' => (string)$pending['return_to'],
        ];
    }

    /**
     * Discard an in-flight login (the callback came back with `error`).
     *
     * @return array{return_to: string}|null null for an unknown state
     */
    public function loginCancel(string $state): ?array
    {
        if ($this->loginStore === null) {
            throw self::noLoginStoreError();
        }
        $pending = $state !== '' ? $this->loginStore->take($state) : null;
        return $pending === null ? null : ['return_to' => (string)$pending['return_to']];
    }

    private function requireLogin(): LoginStore
    {
        if ($this->clientId === null || $this->clientId === '' || $this->clientSecret === null || $this->clientSecret === '') {
            throw new SynthigyError(
                'login needs $clientId + $clientSecret: the code exchange authenticates this '
                . 'process as a confidential client. A browser or native app is a public '
                . 'client and drives the same flow with a PKCE library of its own.',
                'LOGIN_REQUIRES_CONFIDENTIAL_CLIENT',
            );
        }
        return $this->loginStore ?? throw self::noLoginStoreError();
    }

    private static function noLoginStoreError(): SynthigyError
    {
        return new SynthigyError(
            'no login store: pass loginStore: — a LoginStore backed by whatever already holds '
            . 'your sessions ($_SESSION, Redis). MemoryLoginStore only works inside one '
            . 'long-lived process, never under PHP-FPM.',
            'NO_LOGIN_STORE',
        );
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * A JWT's claims, unverified — only for a token from our own authenticated token POST.
     *
     * @return array<string,mixed>|null
     */
    private static function jwtPayload(mixed $jwt): ?array
    {
        if (!is_string($jwt)) {
            return null;
        }
        $parts = explode('.', $jwt);
        if (count($parts) < 2) {
            return null;
        }
        $raw = base64_decode(strtr($parts[1], '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $claims = json_decode($raw, true);
        return is_array($claims) ? $claims : null;
    }
}
