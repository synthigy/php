<?php

/**
 * describe — the server compiles a .xsql operations document and returns
 * the language-neutral IR. This side contains ZERO XSQL grammar; it only
 * calls op:"describe" and persists the result as ops.ir.json, keyed by
 * a sha256 lockfile of the source it was described from.
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

use Synthigy\Client;
use Synthigy\SynthigyError;

use function Synthigy\opDescribeFiles;

function sourceHash(string $source): string
{
    return hash('sha256', $source);
}

/**
 * @param list<array{path: string, source: string}> $files
 * @return array<string,mixed>
 */
function describe(Client $client, array $files): array
{
    $result = $client->exec([opDescribeFiles($files)])[0] ?? [];
    if (!($result['ok'] ?? false)) {
        throw SynthigyError::fromServer($result['error'] ?? null, null, $result['_request_id'] ?? null);
    }
    $data = $result['data'] ?? $result['result'] ?? [];
    return is_array($data) ? $data : [];
}

/**
 * Load the IR from $dir: an offline cache hit when the saved ops.ir.json's
 * sourceHash matches the current source, else a live describe() — never
 * emitted from a stale/mismatched cache.
 *
 * @param list<array{path: string, source: string}> $files
 * @return array<string,mixed>
 */
function loadIr(string $dir, string $source, array $files, string $endpoint, bool $forcePull = false): array
{
    $irPath = irPath($dir);
    $hash = sourceHash($source);

    if (!$forcePull && is_file($irPath)) {
        $cached = json_decode((string)file_get_contents($irPath), true);
        if (is_array($cached) && ($cached['sourceHash'] ?? null) === $hash) {
            return $cached;
        }
    }

    if ($endpoint === '') {
        throw new CodegenError(is_file($irPath) && !$forcePull
            ? 'sources changed since the last pull and no endpoint is set — run under `synthigy exec`, pass --endpoint, or revert the .xsql edit'
            : 'no endpoint — run under `synthigy exec`, pass --endpoint, or set SYNTHIGY_ENDPOINT');
    }
    $ir = describe(envClient($endpoint), $files);
    $ir['sourceHash'] = $hash;
    file_put_contents($irPath, json_encode($ir, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    return $ir;
}

function irPath(string $dir): string
{
    return $dir . '/ops.ir.json';
}
