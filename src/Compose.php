<?php

/**
 * Tree composition — nest flat search-tree / get-tree results.
 *
 * Pure functions, no client. Two-pass id-level indexing (record-by-id +
 * parent->children-ids) so copied children never hold stale subtrees.
 * Cycle-safe: a record participating in a cycle is dropped from that
 * branch.
 */

declare(strict_types=1);

namespace Synthigy;

function recordId(mixed $record): mixed
{
    if (!is_array($record)) {
        return null;
    }
    foreach (['xid', 'euuid', '_eid'] as $k) {
        if (($record[$k] ?? null) !== null) {
            return $record[$k];
        }
    }
    return null;
}

function parentId(mixed $record, string $on): mixed
{
    if (!is_array($record)) {
        return null;
    }
    foreach ([$on, str_replace('-', '_', $on), str_replace('_', '-', $on)] as $k) {
        if (array_key_exists($k, $record)) {
            $v = $record[$k];
            if ($v === null) {
                return null;
            }
            return is_array($v) ? recordId($v) : $v;
        }
    }
    return null;
}

/**
 * @param list<array<string,mixed>> $records
 * @return array{0: array<int|string, array<string,mixed>>, 1: array<int|string, list<int|string>>}
 */
function buildIndexes(array $records, string $on): array
{
    $byId = [];
    $kids = [];
    foreach ($records as $r) {
        $rid = recordId($r);
        if ($rid === null) {
            continue;
        }
        $byId[$rid] = $r;
        $pid = parentId($r, $on);
        if ($pid !== null && $pid !== $rid) {
            $kids[$pid][] = $rid;
        }
    }
    return [$byId, $kids];
}

/**
 * @param array<int|string, array<string,mixed>> $byId
 * @param array<int|string, list<int|string>> $kids
 * @param array<int|string, true> $visited
 * @return array<string,mixed>|null
 */
function buildSubtree(array $byId, array $kids, string $childrenKey, mixed $rid, array $visited): ?array
{
    if (isset($visited[$rid])) {
        return null; // cycle — break
    }
    $visited[$rid] = true;
    $children = [];
    foreach ($kids[$rid] ?? [] as $cid) {
        $sub = buildSubtree($byId, $kids, $childrenKey, $cid, $visited);
        if ($sub !== null) {
            $children[] = $sub;
        }
    }
    return [...$byId[$rid], $childrenKey => $children];
}

/**
 * Compose a flat list into one tree rooted at $rootId (default: the first
 * record). Returns null when the root isn't in the set.
 *
 * @param list<array<string,mixed>> $records
 *
 * @return array<string,mixed>|null
 */
function composeTree(array $records, string $on, mixed $rootId = null, string $childrenKey = '_children'): ?array
{
    if ($records === []) {
        return null;
    }
    if ($on === '') {
        throw new \InvalidArgumentException('composeTree: $on (relation name) is required');
    }
    [$byId, $kids] = buildIndexes($records, $on);
    $root = $rootId ?? recordId($records[0]);
    if (!array_key_exists($root, $byId)) {
        return null;
    }
    return buildSubtree($byId, $kids, $childrenKey, $root, []);
}

/**
 * Compose a flat list into a forest — one tree per record whose parent
 * isn't present in the set.
 *
 * @param list<array<string,mixed>> $records
 * @return list<array<string,mixed>>
 */
function composeForest(array $records, string $on, string $childrenKey = '_children'): array
{
    if ($records === []) {
        return [];
    }
    if ($on === '') {
        throw new \InvalidArgumentException('composeForest: $on (relation name) is required');
    }
    [$byId, $kids] = buildIndexes($records, $on);
    $roots = [];
    foreach ($records as $r) {
        $rid = recordId($r);
        if ($rid === null) {
            continue;
        }
        $pid = parentId($r, $on);
        if ($pid === null || !array_key_exists($pid, $byId) || $pid === $rid) {
            $roots[] = $rid;
        }
    }
    $out = [];
    foreach ($roots as $rid) {
        $tree = buildSubtree($byId, $kids, $childrenKey, $rid, []);
        if ($tree !== null) {
            $out[] = $tree;
        }
    }
    return $out;
}
