<?php

/**
 * describe — the server compiles a .xsql operations document and returns
 * the language-neutral IR. This side contains ZERO XSQL grammar; it only
 * calls op:"describe" and persists the result as <file>.ir.json, keyed by
 * a sha256 lockfile of the source it was described from.
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

use Synthigy\Client;
use Synthigy\SynthigyError;

use function Synthigy\opDescribe;

function sourceHash(string $source): string
{
    return hash('sha256', $source);
}

/** @return array<string,mixed> */
function describe(Client $client, string $source): array
{
    $result = $client->exec([opDescribe($source)])[0] ?? [];
    if (!($result['ok'] ?? false)) {
        throw SynthigyError::fromServer($result['error'] ?? null, null, $result['_request_id'] ?? null);
    }
    $data = $result['data'] ?? $result['result'] ?? [];
    return is_array($data) ? $data : [];
}

/**
 * Load the IR for $xsqlPath: an offline cache hit when the saved
 * <file>.ir.json's sourceHash matches the current source, else a live
 * describe() — never emitted from a stale/mismatched cache.
 *
 * @return array<string,mixed>
 */
function loadIr(string $xsqlPath, string $source, string $endpoint, bool $forcePull = false): array
{
    $irPath = irPath($xsqlPath);
    $hash = sourceHash($source);

    if (!$forcePull && is_file($irPath)) {
        $cached = json_decode((string)file_get_contents($irPath), true);
        if (is_array($cached) && ($cached['sourceHash'] ?? null) === $hash) {
            return $cached;
        }
    }

    $ir = describe(envClient($endpoint), $source);
    $ir['sourceHash'] = $hash;
    file_put_contents($irPath, json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return $ir;
}

function irPath(string $xsqlPath): string
{
    return preg_replace('/\.xsql$/', '', $xsqlPath) . '.ir.json';
}
