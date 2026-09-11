<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Introspection + onboarding. Mixed into Client. These hit endpoints
 * outside the /data envelope (/schema, /lint, /oauth/onboard*), so they
 * bypass Client::post()/exec() and talk to authedRequest() directly.
 */
trait Schema
{
    /**
     * IAM-filtered schema (GET /schema). $entities narrows the pull.
     *
     * @param list<string>|null $entities
     * @return array<string,mixed>
     */
    public function schema(?array $entities = null): array
    {
        $path = '/schema';
        if ($entities) {
            $path .= '?' . http_build_query(['entities' => implode(',', $entities)]);
        }
        $resp = $this->authedRequest('GET', $this->endpoint . $path, null, []);
        if ($resp->status < 200 || $resp->status >= 300) {
            throw new SynthigyError("schema request failed ({$resp->status})", 'HTTP_ERROR', status: $resp->status);
        }
        $decoded = json_decode($resp->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Lint an XSQL source string (POST /lint) -> list of diagnostics.
     *
     * @return list<array<string,mixed>>
     */
    public function lint(string $source, ?string $entity = null, ?string $op = null): array
    {
        $body = ['source' => $source];
        if ($entity !== null) {
            $body['entity'] = $entity;
        }
        if ($op !== null) {
            $body['op'] = $op;
        }
        $resp = $this->authedRequest('POST', $this->endpoint . '/lint', self::encodeJson($body), ['Content-Type' => 'application/json']);
        if ($resp->status < 200 || $resp->status >= 300) {
            throw new SynthigyError("lint request failed ({$resp->status})", 'HTTP_ERROR', status: $resp->status);
        }
        $decoded = json_decode($resp->body, true);
        $diagnostics = is_array($decoded) ? ($decoded['diagnostics'] ?? []) : [];
        return self::asRecordList($diagnostics);
    }

    /** @return array<string,mixed> */
    public function deployedModel(): array
    {
        $data = $this->execOne(opDeployedModel());
        return is_array($data) ? $data : [];
    }

    /** @return array<string,mixed> */
    public function runtimeModel(): array
    {
        $data = $this->execOne(opRuntimeModel());
        return is_array($data) ? $data : [];
    }

    /**
     * Mint a one-time account-claim link (POST /oauth/onboard) for an
     * EXISTING account's $xid. This client's own client-credentials
     * identity must administer the account (RBAC update on User within its
     * owner-group write scope) — else PROVISION_FORBIDDEN.
     *
     * @param list<string>|null $methods restrict the claim page, e.g. ["password"]; null = every active provider + password
     * @return array<string,mixed> {onboard_url, expires_at, user: {xid}}
     */
    public function onboard(
        string $xid,
        ?bool $reset = null,
        ?array $methods = null,
        ?int $ttlSeconds = null,
        ?string $returnUrl = null,
    ): array {
        $body = ['xid' => $xid];
        if ($reset !== null) {
            $body['reset'] = $reset;
        }
        if ($methods !== null) {
            $body['methods'] = $methods;
        }
        if ($ttlSeconds !== null) {
            $body['ttl_seconds'] = $ttlSeconds;
        }
        if ($returnUrl !== null) {
            $body['return_url'] = $returnUrl;
        }
        $resp = $this->authedRequest('POST', $this->endpoint . '/oauth/onboard', self::encodeJson($body), ['Content-Type' => 'application/json']);
        return $this->parseOnboardResponse($resp, 'onboard');
    }

    /**
     * Redeem an onboarding ticket without a browser
     * (POST /oauth/onboard/complete) — must be called by the SAME client
     * that minted it (CLAIM_INVALID otherwise).
     *
     * @return array<string,mixed> {user: {xid}, active}
     */
    public function onboardComplete(string $ticket): array
    {
        $resp = $this->authedRequest('POST', $this->endpoint . '/oauth/onboard/complete', self::encodeJson(['ticket' => $ticket]), ['Content-Type' => 'application/json']);
        return $this->parseOnboardResponse($resp, 'onboard-complete');
    }

    /**
     * Shared response handling for /oauth/onboard[/complete]. On error the
     * wire shape here is {"error": "<snake_case_code>"} — a bare string,
     * unlike the {"error": {code, message}} envelope /data uses — so it's
     * handled separately from SynthigyError::fromServer, which expects the
     * structured shape.
     *
     * @return array<string,mixed>
     */
    private function parseOnboardResponse(Http\HttpResponse $resp, string $label): array
    {
        $decoded = json_decode($resp->body, true);
        if ($resp->status < 200 || $resp->status >= 300) {
            $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
            if (is_string($err) && $err !== '') {
                throw new SynthigyError("{$label} failed: {$err}", strtoupper($err), status: $resp->status);
            }
            throw new SynthigyError("{$label} request failed ({$resp->status})", 'HTTP_ERROR', $resp->body, status: $resp->status);
        }
        return is_array($decoded) ? $decoded : [];
    }
}
