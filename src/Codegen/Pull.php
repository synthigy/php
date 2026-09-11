<?php

/**
 * pull — writes the IAM-filtered schema (GET /schema, as seen by the
 * app's OWN client credentials — never a personal/dev identity, or the
 * generated contract would promise more than the app can actually do) to
 * a JSON file, committed alongside the .xsql sources.
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

use Synthigy\Client;

function envClient(string $endpoint): Client
{
    $cid = getenv('SYNTHIGY_CLIENT_ID') ?: null;
    $csec = getenv('SYNTHIGY_CLIENT_SECRET') ?: null;
    // SYNTHIGY_AUDIENCE: some servers require an explicit client_credentials
    // audience for /data access (opt-in by design — see Client's own
    // $audience doc); a static SYNTHIGY_TOKEN sidesteps this entirely
    // (audience is already baked into the token).
    $audience = getenv('SYNTHIGY_AUDIENCE') ?: null;
    if ($cid && $csec) {
        return new Client($endpoint, clientId: $cid, clientSecret: $csec, audience: $audience);
    }
    $token = getenv('SYNTHIGY_TOKEN');
    return new Client($endpoint, token: $token !== false ? $token : '');
}

/** @return array<string,mixed> */
function pullSchema(string $endpoint, string $outPath): array
{
    $schema = envClient($endpoint)->schema();
    file_put_contents($outPath, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    $n = count($schema['entities'] ?? []);
    $v = $schema['version'] ?? null;
    fwrite(STDOUT, "pulled {$n} entities" . ($v ? " @{$v}" : '') . " -> {$outPath}\n");
    return $schema;
}
