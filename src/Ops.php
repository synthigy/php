<?php

/**
 * Raw wire-operation builders for exec()/batching.
 *
 * Usage:
 *   use function Synthigy\opSlice;
 *   use function Synthigy\opStack;
 *   $client->exec([
 *       opSlice('User', ['xid' => $u], ['roles' => [['xid' => $old]]]),
 *       opStack('User', ['xid' => $u, 'roles' => [['xid' => $new]]]),
 *   ]);
 */

declare(strict_types=1);

namespace Synthigy;

/**
 * @param array<string,mixed>|null $args
 * @return array<string,mixed>
 */
function opSearch(string $entity, ?array $args = null, mixed $selection = null): array
{
    return ['op' => 'search', 'entity' => $entity, 'args' => $args,
            'selections' => normalizeSelection($selection)];
}

/**
 * @param array<string,mixed>|null $args
 * @return array<string,mixed>
 */
function opGet(string $entity, ?array $args = null, mixed $selection = null): array
{
    return ['op' => 'get', 'entity' => $entity, 'args' => $args,
            'selections' => normalizeSelection($selection)];
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function opSync(string $entity, array $data, bool $returning = false): array
{
    return ['op' => 'sync', 'entity' => $entity, 'data' => $data,
            'returning' => $returning];
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function opStack(string $entity, array $data, bool $returning = false): array
{
    return ['op' => 'stack', 'entity' => $entity, 'data' => $data,
            'returning' => $returning];
}

/**
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function opSlice(string $entity, array $args, mixed $selection = null): array
{
    return ['op' => 'slice', 'entity' => $entity, 'args' => $args,
            'selections' => normalizeSelection($selection)];
}

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function opDelete(string $entity, array $data): array
{
    return ['op' => 'delete', 'entity' => $entity, 'data' => $data];
}

/**
 * @param array<string,mixed> $args
 * @return array<string,mixed>
 */
function opPurge(string $entity, array $args, mixed $selection = null): array
{
    return ['op' => 'purge', 'entity' => $entity, 'args' => $args,
            'selections' => normalizeSelection($selection)];
}

/**
 * @param array<string,mixed>|null $args
 * @return array<string,mixed>
 */
function opSearchTree(string $entity, string $on, ?array $args = null, mixed $selection = null): array
{
    return ['op' => 'search-tree', 'entity' => $entity, 'on' => $on, 'args' => $args,
            'selections' => normalizeSelection($selection)];
}

/**
 * $root is the scalar xid of the root record (matched against each row's
 * own identity field, not an args map — see compose_tree's root_id).
 *
 * @return array<string,mixed>
 */
function opGetTree(string $entity, mixed $root, string $on, mixed $selection = null): array
{
    return ['op' => 'get-tree', 'entity' => $entity, 'root' => $root, 'on' => $on,
            'selections' => normalizeSelection($selection)];
}

/**
 * @param array<array-key,mixed> $params
 * @return array<string,mixed>
 */
function opSqlTemplate(string $template, array $params = []): array
{
    return ['op' => 'sql-template', 'template' => $template, 'params' => $params];
}

/**
 * Wraps a bare XSQL body with a synthetic `@<verb> _q` header unless the
 * source already declares one (starts with "@") — the server derives
 * verb/entity/selections/args from the document either way.
 */
function xsqlDocument(string $source, string $op = 'search'): string
{
    if (str_starts_with(ltrim($source, " \t\n\r"), '@')) {
        return $source;
    }
    return "@{$op} _q\n{$source}";
}

/**
 * @param array<string,mixed>|null $params
 * @return array<string,mixed>
 */
function opQuery(string $xsql, ?array $params = null, string $op = 'search'): array
{
    $o = ['op' => 'xsql', 'xsql' => xsqlDocument($xsql, $op)];
    if ($params !== null) {
        $o['params'] = $params;
    }
    return $o;
}

/** @return array{op: 'deploy', data: string} */
function opDeploy(string $exportContents): array
{
    return ['op' => 'deploy', 'data' => $exportContents];
}

/** @return array{op: 'delete', entity: 'dataset', data: array{xid: string}} */
function opDestroy(string $datasetXid): array
{
    return ['op' => 'delete', 'entity' => 'dataset', 'data' => ['xid' => $datasetXid]];
}

/** @return array{op: 'deployed-model'} */
function opDeployedModel(): array
{
    return ['op' => 'deployed-model'];
}

/** @return array{op: 'runtime-model'} */
function opRuntimeModel(): array
{
    return ['op' => 'runtime-model'];
}

/** @return array{op: 'describe', source: string} */
function opDescribe(string $source): array
{
    return ['op' => 'describe', 'source' => $source];
}

/**
 * Per-@batch-member result unwrap, used by generated codegen batch methods
 * (Client::exec()'s raw per-op results, one step past execOne()'s own
 * inline version of this same unwrap): ok -> data (a "get" member ->
 * record|null, every other verb -> a list, defaulting a null payload to
 * []); not ok -> a SynthigyError VALUE, never thrown — a batch's own
 * failure never aborts the whole round trip (mirrors sdk/clj's generator).
 *
 * @param array<string,mixed> $result
 */
function batchResult(array $result, string $op): mixed
{
    if (!($result['ok'] ?? false)) {
        $err = $result['error'] ?? [];
        return new SynthigyError(
            (string)($err['message'] ?? 'operation failed'),
            (string)($err['code'] ?? 'OPERATION_FAILED'),
            $err['details'] ?? null,
        );
    }
    $data = $result['data'] ?? null;
    if ($op === 'get') {
        return $data;
    }
    return $data ?? [];
}

/**
 * A fresh 22-char Base58 xid — client-minted identity for sync/stack, the same
 * derivation the server uses (UUID bytes -> base58, left-padded with '1').
 *
 * Mint one before a write to know a record's id up front, or to make a retried
 * write idempotent — the server accepts a caller-supplied id as-is, and the
 * alternative ($returning) costs the full echo on every write.
 */
function newXid(): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $bytes = array_values(unpack('C*', random_bytes(16)) ?: []);
    // UUIDv4 bit layout, so the value round-trips the server's uuid->nanoid.
    $bytes[6] = ($bytes[6] & 0x0F) | 0x40;
    $bytes[8] = ($bytes[8] & 0x3F) | 0x80;
    // Schoolbook base-256 -> base-58, the same conversion Bitcoin-style base58
    // libraries use.
    $digits = [0];
    foreach ($bytes as $byte) {
        $carry = $byte;
        foreach ($digits as $i => $d) {
            $x = $d * 256 + $carry;
            $digits[$i] = $x % 58;
            $carry = intdiv($x, 58);
        }
        while ($carry > 0) {
            $digits[] = $carry % 58;
            $carry = intdiv($carry, 58);
        }
    }
    $s = '';
    foreach (array_reverse($digits) as $d) {
        $s .= $alphabet[$d];
    }
    return str_repeat('1', max(0, 22 - strlen($s))) . $s;
}
