<?php

/**
 * CLI commands — pull | gen | check. Mirrors `python3 -m synthigy.codegen`'s
 * argument shape (bin/synthigy-codegen is the thin argv entry point).
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

/**
 * @param list<string> $argv command name and its own args already stripped
 * @return array{0: list<string>, 1: array<string,string|true>} [positional, flags]
 */
function parseArgs(array $argv): array
{
    $positional = [];
    $flags = [];
    $i = 0;
    $n = count($argv);
    while ($i < $n) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $name = substr($a, 2);
            $next = $argv[$i + 1] ?? null;
            if ($next !== null && !str_starts_with($next, '--')) {
                $flags[$name] = $next;
                $i += 2;
            } else {
                $flags[$name] = true;
                $i += 1;
            }
        } else {
            $positional[] = $a;
            $i += 1;
        }
    }
    return [$positional, $flags];
}

/** Locate the schema snapshot beside the .xsql — legacy-first so an existing file wins. */
function resolveSchemaPath(string $xsqlPath, ?string $override): ?string
{
    if ($override) {
        return $override;
    }
    $dir = dirname($xsqlPath);
    foreach (['synthigy.schema.json', 'schema.json'] as $name) {
        $p = $dir . '/' . $name;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * @param array<string, string|true> $flags
 */
function resolveEndpoint(array $flags): string
{
    $endpoint = (is_string($flags['endpoint'] ?? null) ? $flags['endpoint'] : null) ?? (getenv('SYNTHIGY_ENDPOINT') ?: null);
    if (!$endpoint) {
        throw new CodegenError('no endpoint — pass --endpoint or set SYNTHIGY_ENDPOINT');
    }
    return $endpoint;
}

/** @param list<string> $argv */
function cmdPull(array $argv): int
{
    [$pos] = parseArgs($argv);
    $endpoint = $pos[0] ?? (getenv('SYNTHIGY_ENDPOINT') ?: null);
    if (!$endpoint) {
        throw new CodegenError('usage: synthigy-codegen pull <endpoint> [out]');
    }
    $out = $pos[1] ?? 'schema.json';
    pullSchema($endpoint, $out);
    return 0;
}

/** @param list<string> $argv */
function cmdGen(array $argv): int
{
    [$pos, $flags] = parseArgs($argv);
    $xsqlPath = $pos[0] ?? throw new CodegenError('usage: synthigy-codegen gen <file.xsql> [--schema P] [--out P] [--no-writes] [--pull] [--endpoint URL] [--namespace NS]');
    if (!is_file($xsqlPath)) {
        throw new CodegenError("no such file: {$xsqlPath}");
    }
    $source = (string)file_get_contents($xsqlPath);
    $writes = !($flags['no-writes'] ?? false);
    $forcePull = (bool)($flags['pull'] ?? false);
    $namespace = is_string($flags['namespace'] ?? null) ? $flags['namespace'] : 'Generated';

    $endpoint = is_string($flags['endpoint'] ?? null) ? $flags['endpoint'] : (getenv('SYNTHIGY_ENDPOINT') ?: '');
    if ($endpoint === '') {
        throw new CodegenError('no endpoint — pass --endpoint or set SYNTHIGY_ENDPOINT');
    }
    $ir = loadIr($xsqlPath, $source, $endpoint, $forcePull);

    $schema = null;
    if ($writes) {
        $schemaPath = resolveSchemaPath($xsqlPath, is_string($flags['schema'] ?? null) ? $flags['schema'] : null);
        if ($schemaPath === null) {
            throw new CodegenError(
                "no schema found beside {$xsqlPath} — pass --schema, run "
                . "`synthigy-codegen pull` first, or use --no-writes",
            );
        }
        $decoded = json_decode((string)file_get_contents($schemaPath), true);
        if (!is_array($decoded)) {
            throw new CodegenError("failed to parse schema: {$schemaPath}");
        }
        $schema = $decoded;
    }

    $out = is_string($flags['out'] ?? null) ? $flags['out'] : (preg_replace('/\.xsql$/', '', $xsqlPath) . '_gen.php');
    $code = render($ir, $schema, $writes, $namespace, basename($xsqlPath));
    file_put_contents($out, $code);

    // shell_exec returns null when it can't run and false on failure —
    // only an actual string is a lint verdict worth judging.
    $lint = @shell_exec('php -l ' . escapeshellarg($out) . ' 2>&1');
    if (is_string($lint) && !str_contains($lint, 'No syntax errors detected')) {
        throw new CodegenError("generated file failed php -l:\n{$lint}");
    }

    fwrite(STDOUT, "generated {$out}\n");
    return 0;
}

/** @param list<string> $argv */
function cmdCheck(array $argv): int
{
    [$pos, $flags] = parseArgs($argv);
    $xsqlPath = $pos[0] ?? throw new CodegenError('usage: synthigy-codegen check <file.xsql> [--endpoint URL]');
    if (!is_file($xsqlPath)) {
        throw new CodegenError("no such file: {$xsqlPath}");
    }
    $source = (string)file_get_contents($xsqlPath);
    $hash = sourceHash($source);

    $irFile = irPath($xsqlPath);
    if (!is_file($irFile)) {
        throw new CodegenError("no {$irFile} — run: synthigy-codegen gen {$xsqlPath}");
    }
    $cached = json_decode((string)file_get_contents($irFile), true);
    if (!is_array($cached) || ($cached['sourceHash'] ?? null) !== $hash) {
        throw new CodegenError(
            "source drift: {$xsqlPath} changed since the last gen — re-run: "
            . "synthigy-codegen gen {$xsqlPath}",
        );
    }

    $endpoint = is_string($flags['endpoint'] ?? null) ? $flags['endpoint'] : (getenv('SYNTHIGY_ENDPOINT') ?: null);
    $hasCreds = (bool)(getenv('SYNTHIGY_CLIENT_ID') || getenv('SYNTHIGY_TOKEN'));
    if ($endpoint && $hasCreds) {
        $live = describe(envClient($endpoint), $source);
        $liveOps = $live['operations'] ?? null;
        $cachedOps = $cached['operations'] ?? null;
        if ($liveOps !== $cachedOps) {
            throw new CodegenError(
                "live describe differs from the saved IR ({$irFile}) — re-run: "
                . "synthigy-codegen gen {$xsqlPath} --pull",
            );
        }
        fwrite(STDOUT, "ok (offline hash + live describe-diff): {$xsqlPath}\n");
        return 0;
    }

    fwrite(STDOUT, "ok (offline hash only — set SYNTHIGY_ENDPOINT + creds for a live diff): {$xsqlPath}\n");
    return 0;
}

/** @param list<string> $argv full argv, argv[0] = the command name */
function main(array $argv): int
{
    $cmd = $argv[0] ?? null;
    $rest = array_slice($argv, 1);
    try {
        return match ($cmd) {
            'pull' => cmdPull($rest),
            'gen' => cmdGen($rest),
            'check' => cmdCheck($rest),
            default => throw new CodegenError('usage: synthigy-codegen pull|gen|check ...'),
        };
    } catch (CodegenError) {
        return 1;
    }
}
