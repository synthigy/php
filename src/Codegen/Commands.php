<?php

/**
 * CLI commands — pull | gen | check. Mirrors `python3 -m synthigy.codegen`'s
 * argument shape (bin/synthigy-codegen is the thin argv entry point).
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

/** Flags that never take a value — `gen --pull xsql/` must not read `xsql/` as `--pull`'s. */
const BOOLEAN_FLAGS = ['pull', 'no-writes'];

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
            if ($next !== null && !str_starts_with($next, '--') && !in_array($name, BOOLEAN_FLAGS, true)) {
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

/**
 * Files as given; directories expanded recursively, sorted by relative path.
 *
 * @param list<string> $inputs
 * @return list<string>
 */
function xsqlFiles(array $inputs): array
{
    $paths = [];
    foreach ($inputs as $a) {
        if (is_dir($a)) {
            $root = rtrim($a, '/');
            $found = [];
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.xsql')) {
                    $found[] = substr($f->getPathname(), strlen($root) + 1);
                }
            }
            sort($found, SORT_STRING);
            foreach ($found as $rel) {
                $paths[] = $root . '/' . $rel;
            }
        } elseif (is_file($a)) {
            $paths[] = $a;
        } else {
            throw new CodegenError("no such file: {$a}");
        }
    }
    if ($paths === []) {
        throw new CodegenError('no .xsql files found in ' . implode(' ', $inputs));
    }
    return $paths;
}

/**
 * Home of the IR and schema snapshots: the first input if a directory, else its parent.
 *
 * @param list<string> $inputs
 */
function xsqlDir(array $inputs): string
{
    return is_dir($inputs[0]) ? rtrim($inputs[0], '/') : dirname($inputs[0]);
}

/**
 * Merge into one describe document; only the first file keeps its buffer-scoped `@workspace`.
 *
 * @param list<string> $paths
 */
function readSources(array $paths): string
{
    $parts = [];
    foreach ($paths as $i => $p) {
        $src = (string)file_get_contents($p);
        $parts[] = $i === 0 ? $src : implode("\n", array_filter(
            explode("\n", $src),
            fn(string $l) => !preg_match('/^@workspace\b/', $l),
        ));
    }
    return implode("\n\n", $parts);
}

/**
 * One entry per file for describe, paths relative to the xsql dir.
 *
 * @param list<string> $paths
 * @return list<array{path: string, source: string}>
 */
function readFiles(array $paths, string $dir): array
{
    $root = rtrim((string)(realpath($dir) ?: $dir), '/') . '/';
    return array_map(function (string $p) use ($root): array {
        $abs = (string)(realpath($p) ?: $p);
        return [
            'path' => str_starts_with($abs, $root) ? substr($abs, strlen($root)) : basename($p),
            'source' => (string)file_get_contents($p),
        ];
    }, $paths);
}

/** Locate the schema snapshot in the xsql dir — legacy-first so an existing file wins. */
function resolveSchemaPath(string $dir, ?string $override): ?string
{
    if ($override) {
        return $override;
    }
    foreach (['synthigy.schema.json', 'schema.json'] as $name) {
        $p = $dir . '/' . $name;
        if (is_file($p)) {
            return $p;
        }
    }
    return null;
}

/**
 * One line on $out when the schema's deploy stamp is older than the model at
 * /.well-known/synthigy. Silent on any failure: generation stays offline-capable.
 *
 * @param resource $out
 */
function warnStaleSchema(string $endpoint, ?string $schemaPath, $out = STDERR): void
{
    if ($endpoint === '' || $schemaPath === null || !is_file($schemaPath)) {
        return;
    }
    $local = json_decode((string)file_get_contents($schemaPath), true)['datasets'] ?? [];
    $ctx = stream_context_create(['http' => ['timeout' => 2, 'ignore_errors' => false]]);
    $body = @file_get_contents(rtrim($endpoint, '/') . '/.well-known/synthigy', false, $ctx);
    if (!is_string($body)) {
        return;
    }
    $remote = json_decode($body, true)['datasets'] ?? null;
    if (!is_array($remote) || !is_array($local)) {
        return;
    }
    $instant = static function ($s) {
        try {
            return is_string($s) ? (new \DateTimeImmutable($s))->format('U.u') : $s;
        } catch (\Exception) {
            return $s;
        }
    };
    $changed = 0;
    foreach ($remote as $id => $at) {
        if (!isset($local[$id]) || $instant($local[$id]['deployed-at'] ?? null) !== $instant($at)) {
            $changed++;
        }
    }
    foreach ($local as $id => $_) {
        if (!array_key_exists($id, $remote)) {
            $changed++;
        }
    }
    if ($changed > 0) {
        fwrite($out, "warning: {$schemaPath} is stale against {$endpoint} ({$changed} datasets changed since the pull) — re-run with --pull\n");
    }
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
    if ($pos === []) {
        throw new CodegenError('usage: synthigy-codegen gen <xsql...> [--schema P] [--out P] [--no-writes] [--pull] [--endpoint URL] [--namespace NS]');
    }
    $paths = xsqlFiles($pos);
    $source = readSources($paths);
    $dir = xsqlDir($pos);
    $writes = !($flags['no-writes'] ?? false);
    $forcePull = (bool)($flags['pull'] ?? false);
    $namespace = is_string($flags['namespace'] ?? null) ? $flags['namespace'] : 'Generated';

    $endpoint = is_string($flags['endpoint'] ?? null) ? $flags['endpoint'] : (getenv('SYNTHIGY_ENDPOINT') ?: '');
    $ir = loadIr($dir, $source, readFiles($paths, $dir), $endpoint, $forcePull);

    $schema = null;
    $explicit = is_string($flags['schema'] ?? null) ? $flags['schema'] : null;
    $schemaPath = resolveSchemaPath($dir, $explicit);
    $pulled = false;
    if ($writes) {
        if ($explicit === null && ($schemaPath === null || $forcePull)) {
            if ($endpoint === '') {
                throw new CodegenError(
                    "no schema in {$dir} and no endpoint — run under `synthigy exec`, "
                    . 'pass --endpoint or --schema, or use --no-writes',
                );
            }
            $schemaPath = $dir . '/schema.json';
            pullSchema($endpoint, $schemaPath);
            $pulled = true;
        }
        if ($schemaPath === null) {
            throw new CodegenError("no schema found in {$dir} — pass --schema, or use --no-writes");
        }
        $decoded = json_decode((string)file_get_contents($schemaPath), true);
        if (!is_array($decoded)) {
            throw new CodegenError("failed to parse schema: {$schemaPath}");
        }
        $schema = $decoded;
    }
    if (!$pulled) {
        warnStaleSchema($endpoint, $schemaPath);
    }

    $single = count($pos) === 1 && !is_dir($pos[0]);
    $out = is_string($flags['out'] ?? null) ? $flags['out']
        : ($single ? preg_replace('/\.xsql$/', '', $pos[0]) : $dir . '/ops') . '_gen.php';
    $code = render($ir, $schema, $writes, $namespace, implode(' ', $pos));
    $outDir = dirname($out);
    if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
        throw new CodegenError("cannot create {$outDir}");
    }
    if (file_put_contents($out, $code) === false) {
        throw new CodegenError("cannot write {$out}");
    }

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
    if ($pos === []) {
        throw new CodegenError('usage: synthigy-codegen check <xsql...> [--endpoint URL]');
    }
    $paths = xsqlFiles($pos);
    $source = readSources($paths);
    $hash = sourceHash($source);
    $inputs = implode(' ', $pos);

    $irFile = irPath(xsqlDir($pos));
    if (!is_file($irFile)) {
        throw new CodegenError("no {$irFile} — run: synthigy-codegen gen {$inputs}");
    }
    $cached = json_decode((string)file_get_contents($irFile), true);
    if (!is_array($cached) || ($cached['sourceHash'] ?? null) !== $hash) {
        throw new CodegenError(
            "source drift: {$inputs} changed since the last gen — re-run: "
            . "synthigy-codegen gen {$inputs}",
        );
    }

    $endpoint = is_string($flags['endpoint'] ?? null) ? $flags['endpoint'] : (getenv('SYNTHIGY_ENDPOINT') ?: null);
    $hasCreds = (bool)(getenv('SYNTHIGY_CLIENT_ID') || getenv('SYNTHIGY_TOKEN'));
    if ($endpoint && $hasCreds) {
        $live = describe(envClient($endpoint), readFiles($paths, xsqlDir($pos)));
        $liveOps = $live['operations'] ?? null;
        $cachedOps = $cached['operations'] ?? null;
        if ($liveOps !== $cachedOps) {
            throw new CodegenError(
                "live describe differs from the saved IR ({$irFile}) — re-run: "
                . "synthigy-codegen gen {$inputs} --pull",
            );
        }
        fwrite(STDOUT, "ok (offline hash + live describe-diff): {$inputs}\n");
        return 0;
    }

    fwrite(STDOUT, "ok (offline hash only — set SYNTHIGY_ENDPOINT + creds for a live diff): {$inputs}\n");
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
    } catch (\Synthigy\SynthigyError $e) {
        fwrite(STDERR, "codegen: describe failed: {$e->code}: {$e->getMessage()}\n");
        return 1;
    }
}
