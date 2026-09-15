<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * CRUD + XSQL verbs. Mixed into Client — thin wrappers over
 * Client::execOne()/Client::exec() and the opX() builders.
 *
 * Contract pinned by the port: get() args are flat unique-key values (no
 * implicit _eq wrapping) and returns null when nothing matches; search/
 * purge/sqlTemplate default to [] rather than null; empty relations are
 * OMITTED by the wire, never filled back in as [] client-side.
 */
trait Crud
{
    /**
     * Wire payload -> a guaranteed list of records. json_decode gives a
     * plain array, which is only a *list* if the server really sent a JSON
     * array; array_values() makes that true rather than merely asserted,
     * so callers indexing [0] or counting can trust it.
     *
     * @return list<array<string,mixed>>
     */
    private static function asRecordList(mixed $data): array
    {
        if (!is_array($data)) {
            return [];
        }
        /** @var list<array<string,mixed>> */
        return array_values(array_filter($data, is_array(...)));
    }

    /**
     * @param array<string,mixed>|null $args
     * @return list<array<string,mixed>>
     */
    public function search(string $entity, ?array $args = null, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::asRecordList($this->execOne(opSearch($entity, $args, $selection), $actingAs, $keyFormat));
    }

    /**
     * Unique-key args (no implicit _eq). Returns null when no record
     * matches.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public function get(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): ?array
    {
        $data = $this->execOne(opGet($entity, $args, $selection), $actingAs, $keyFormat);
        return is_array($data) ? $data : null;
    }

    /**
     * Writes are silent by default — the server answers ['count' => n]. Pass
     * $returning for the written records, or mint ids up front with newXid(),
     * which is the cheap way to know what you wrote.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function sync(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null, bool $returning = false): array
    {
        return $this->execOne(opSync($entity, $data, $returning), $actingAs, $keyFormat);
    }

    /**
     * Same $returning contract as sync.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function stack(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null, bool $returning = false): array
    {
        return $this->execOne(opStack($entity, $data, $returning), $actingAs, $keyFormat);
    }

    /**
     * Surgical unlink of specific relation members (no delete). Returns a
     * map of relation-name -> success.
     *
     * @param array<string,mixed> $args
     * @return array<string,bool>
     */
    public function slice(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return $this->execOne(opSlice($entity, $args, $selection), $actingAs, $keyFormat);
    }

    /** @param array<string,mixed> $data */
    public function delete(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null): bool
    {
        return (bool)$this->execOne(opDelete($entity, $data), $actingAs, $keyFormat);
    }

    /**
     * Deploy a dataset version from a modeler export. Pass the export
     * file's contents verbatim — the server decodes it. Requires
     * dataset:deploy. The ack carries `deployed`, `version` and the
     * `dataset` xid destroy() takes.
     *
     * @return array<string,mixed>
     */
    public function deploy(string $exportContents, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        $data = $this->execOne(opDeploy($exportContents), $actingAs, $keyFormat);
        return is_array($data) ? $data : [];
    }

    /**
     * Destroy a dataset — every version, table and row. Requires
     * dataset:delete. Idempotent.
     */
    public function destroy(string $datasetXid, ?string $actingAs = null, ?string $keyFormat = null): bool
    {
        return (bool)$this->execOne(opDestroy($datasetXid), $actingAs, $keyFormat);
    }

    /**
     * Hard-deletes every record matching $args (RLS-scoped). Returns the
     * purged records.
     *
     * @param array<string,mixed> $args
     * @return list<array<string,mixed>>
     */
    public function purge(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::asRecordList($this->execOne(opPurge($entity, $args, $selection), $actingAs, $keyFormat));
    }

    /**
     * ERD-aware SQL template ({entity.field} / {entity->rel} placeholders).
     * $params bind positionally to `?`, or by name for a template using
     * `?name:type` placeholders (what @sql-template codegen emits).
     *
     * @param array<array-key,mixed> $params
     * @return list<array<string,mixed>>
     */
    public function sqlTemplate(string $template, array $params = [], ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::asRecordList($this->execOne(opSqlTemplate($template, $params), $actingAs, $keyFormat));
    }

    /**
     * Runs an XSQL selection-DSL string with optional ?name:type
     * placeholders. STRICT wire: XSQL travels only as the `xsql` document
     * op — a bare rooted body gets a synthetic `@<verb> _q` header
     * client-side (verb defaults to "search"; pass op: "get" for a
     * unique-key read, which returns a single record instead of a list).
     * The server derives verb/entity/selections/args from the document.
     *
     * @param array<string,mixed>|null $params
     */
    public function query(string $xsql, ?array $params = null, string $op = 'search', ?string $actingAs = null, ?string $keyFormat = null): mixed
    {
        return $this->execOne(opQuery($xsql, $params, $op), $actingAs, $keyFormat);
    }

    /**
     * Compose a flat search-tree result into a forest (one tree per root).
     * Pass raw: true for the flat list instead.
     *
     * @param array<string,mixed>|null $args
     * @return list<array<string,mixed>>
     */
    public function searchTree(
        string $entity,
        string $on,
        ?array $args = null,
        mixed $selection = null,
        bool $raw = false,
        string $childrenKey = '_children',
        ?string $actingAs = null,
        ?string $keyFormat = null,
    ): array {
        $flat = self::asRecordList($this->execOne(opSearchTree($entity, $on, $args, $selection), $actingAs, $keyFormat));
        return $raw ? $flat : composeForest($flat, $on, $childrenKey);
    }

    /**
     * Compose a flat get-tree result into one tree rooted at $root (the
     * scalar xid of the root record). Pass raw: true for the flat list
     * instead.
     */
    public function getTree(
        string $entity,
        mixed $root,
        string $on,
        mixed $selection = null,
        bool $raw = false,
        string $childrenKey = '_children',
        ?string $actingAs = null,
        ?string $keyFormat = null,
    ): mixed {
        $flat = self::asRecordList($this->execOne(opGetTree($entity, $root, $on, $selection), $actingAs, $keyFormat));
        return $raw ? $flat : composeTree($flat, $on, $root, $childrenKey);
    }
}
