<?php

declare(strict_types=1);

namespace Synthigy;

use Synthigy\Auth\StaticTokenSource;
use Synthigy\Auth\SupervisedTokenSource;
use Synthigy\Auth\TokenManager;
use Synthigy\Auth\TokenSource;
use Synthigy\Http\CurlTransport;
use Synthigy\Http\HttpTransport;

/**
 * A Synthigy /data client. CRUD + auth + codegen only — no watch/SSE (see
 * docs/plans/PLAN-PHP-SDK.md for why: PHP-FPM has no long-lived process to
 * hold a streaming connection).
 *
 * Auth resolution, in order: an explicit $token (including ""  for an
 * authless dev server) > $clientId+$clientSecret (OAuth client
 * credentials) > SYNTHIGY_SUPERVISED=1 (supervised stdio, CLI only) >
 * the SYNTHIGY_TOKEN env var (a snapshot, not a live source) > a
 * SynthigyError(code: NO_TOKEN) that teaches the fix.
 *
 * $audience binds a default `client_credentials` audience for every /data
 * call this Client makes — set it to the platform's `/data` audience
 * (e.g. `https://synthigy.com`) when the server requires one explicitly
 * (its audience model is opt-in by design: omitting it mints an
 * identity-only token that 401s on /data, no matter how the client is
 * configured server-side). Irrelevant in static-token / supervised mode.
 */
final class Client
{
    use Crud;
    use Schema;

    private readonly string $endpoint;
    private readonly string $dataUrl;
    private readonly HttpTransport $transport;
    private readonly ?TokenSource $tokenSource; // null in static-token mode
    private readonly ?string $staticToken;
    private readonly ?string $defaultActingAs;
    private readonly ?string $defaultKeyFormat;
    private readonly ?float $defaultTimeoutSeconds;
    private readonly ?string $defaultAudience;

    public function __construct(
        string $endpoint,
        ?string $token = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $scope = null,
        ?string $actingAs = null,
        ?string $keyFormat = null,
        ?float $timeoutSeconds = null,
        ?string $audience = null,
        ?HttpTransport $transport = null,
    ) {
        if ($endpoint === '') {
            throw new SynthigyError('endpoint is required', 'INVALID_BODY');
        }
        $this->endpoint = rtrim($endpoint, '/');
        $this->dataUrl = $this->endpoint . '/data';
        $this->transport = $transport ?? new CurlTransport();
        $this->defaultActingAs = $actingAs;
        $this->defaultKeyFormat = $keyFormat;
        $this->defaultTimeoutSeconds = $timeoutSeconds;
        // The platform's audience model is opt-in by design (never a
        // default, never implied by a client's role/API links —
        // docs/plans/PLAN-AUDIENCE-BINDING.md REV3): a client_credentials
        // mint with no `audience` resolves to the identity-only OIDC
        // audience, not the /data-capable platform one, no matter how the
        // client is configured server-side. $audience binds it ONCE here
        // instead of threading it through every call site.
        $this->defaultAudience = $audience;

        if ($token !== null) {
            $this->tokenSource = null;
            $this->staticToken = $token;
        } elseif ($clientId !== null && $clientSecret !== null) {
            $this->tokenSource = new TokenManager(
                $this->transport,
                $this->endpoint . '/oauth/token',
                $clientId,
                $clientSecret,
                $scope,
            );
            $this->staticToken = null;
        } elseif (getenv('SYNTHIGY_SUPERVISED') === '1') {
            // The pipe beats the env var: exec injects the cached token AND
            // supervises; only the pipe refreshes mid-run.
            $this->tokenSource = new SupervisedTokenSource();
            $this->staticToken = null;
        } elseif (($envToken = getenv('SYNTHIGY_TOKEN')) !== false && $envToken !== '') {
            // A snapshot, not a live source: exec/connect refresh and
            // rewrite the profile's cache on THEIR next run, not this one.
            $this->tokenSource = null;
            $this->staticToken = $envToken;
        } else {
            throw self::noTokenError();
        }
    }

    private static function noTokenError(): SynthigyError
    {
        return new SynthigyError(
            'no Synthigy token: pass $token, or $clientId + $clientSecret, or set '
            . 'SYNTHIGY_TOKEN, or run under `synthigy exec` (or a Synthigy agent) '
            . 'with SYNTHIGY_SUPERVISED=1 so a parent can supply one.',
            'NO_TOKEN',
        );
    }

    /**
     * An access token for $audience, falling back to the client's
     * configured default audience (constructor `audience:`), then to no
     * audience at all (the platform's identity-only default) if neither
     * was set.
     */
    public function token(?string $audience = null): string
    {
        $aud = $audience ?? $this->defaultAudience;
        return $this->tokenSource?->getToken($aud) ?? ($this->staticToken ?? '');
    }

    private function requestId(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }

    /**
     * json_encode() returns false on data it can't represent — invalid
     * UTF-8 in a string field is the realistic way to hit this, and it
     * must surface as a typed SDK error rather than a `false` leaking
     * into the transport as a request body.
     *
     * @param array<string,mixed> $value
     */
    private static function encodeJson(array $value): string
    {
        $encoded = json_encode($value);
        if ($encoded === false) {
            throw new SynthigyError(
                'failed to encode request as JSON: ' . json_last_error_msg(),
                'INVALID_BODY',
            );
        }
        return $encoded;
    }

    /**
     * POST /data; maps 403/non-2xx to SynthigyError, retries once on 401.
     *
     * @param array<string,mixed> $body
     * @return array{0: array<string,mixed>, 1: ?string} [decoded body, request id]
     */
    private function post(array $body): array
    {
        $resp = $this->authedRequest('POST', $this->dataUrl, self::encodeJson($body), ['Content-Type' => 'application/json']);
        $requestId = $resp->header('x-request-id');
        $decoded = json_decode($resp->body, true);

        if ($resp->status === 403) {
            $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            throw SynthigyError::fromServer($err ?? ['message' => 'Forbidden', 'code' => 'FORBIDDEN'], 403, $requestId);
        }
        if ($resp->status < 200 || $resp->status >= 300) {
            $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if ($err !== null) {
                throw SynthigyError::fromServer($err, $resp->status, $requestId);
            }
            throw new SynthigyError('Request failed (' . $resp->status . ')', 'HTTP_ERROR', $resp->body, status: $resp->status, requestId: $requestId);
        }

        return [is_array($decoded) ? $decoded : [], $requestId];
    }

    /**
     * Authenticated request with a single 401-clear-and-retry.
     *
     * @param array<string,string> $headers
     */
    private function authedRequest(string $method, string $url, ?string $body, array $headers): Http\HttpResponse
    {
        $resp = $this->attempt($method, $url, $body, $headers);
        if ($resp->status === 401 && $this->tokenSource !== null) {
            $this->tokenSource->clear();
            $resp = $this->attempt($method, $url, $body, $headers);
        }
        if ($resp->status === 401) {
            throw new SynthigyError('Unauthorized', 'UNAUTHORIZED', status: 401, requestId: $resp->header('x-request-id'));
        }
        return $resp;
    }

    /** @param array<string,string> $headers */
    private function attempt(string $method, string $url, ?string $body, array $headers): Http\HttpResponse
    {
        $tok = $this->token();
        if ($tok !== '') {
            $headers['Authorization'] = 'Bearer ' . $tok;
        }
        $headers['X-Request-Id'] ??= $this->requestId();
        return $this->transport->request($method, $url, $body, $headers, $this->defaultTimeoutSeconds);
    }

    /**
     * Execute raw operations in one round trip; returns the per-op result
     * list (the @batch/overview wire shape). $actingAs multiplexes
     * identity: it overrides the client default so a BFF can run one op
     * batch under a specific logged-in user's permissions.
     *
     * @param list<array<string,mixed>> $operations
     * @return list<array<string,mixed>>
     */
    public function exec(array $operations, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        $body = ['operations' => $operations];
        $aa = $actingAs ?? $this->defaultActingAs;
        if ($aa !== null) {
            $body['acting_as'] = $aa;
        }
        $kf = $keyFormat ?? $this->defaultKeyFormat;
        if ($kf !== null) {
            $body['key_format'] = $kf;
        }

        [$resp, $requestId] = $this->post($body);
        if (isset($resp['error'])) {
            throw SynthigyError::fromServer($resp['error'], null, $requestId);
        }
        $results = $resp['results'] ?? [];
        if (!is_array($results)) {
            $results = [];
        }
        if ($requestId !== null) {
            foreach ($results as &$r) {
                if (is_array($r)) {
                    $r['_request_id'] = $requestId;
                }
            }
            unset($r);
        }
        return array_values($results);
    }

    /** @param array<string,mixed> $op */
    private function execOne(array $op, ?string $actingAs = null, ?string $keyFormat = null): mixed
    {
        $result = $this->exec([$op], $actingAs, $keyFormat)[0] ?? [];
        if (!($result['ok'] ?? false)) {
            throw SynthigyError::fromServer($result['error'] ?? null, null, $result['_request_id'] ?? null);
        }
        return $result['data'] ?? null;
    }
}
