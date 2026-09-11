<?php

/**
 * IR (+ schema for the write tier) -> one generated PHP source file.
 *
 * Two-tier model, same as every other SDK's codegen: the server owns the
 * XSQL grammar (op:"describe" -> language-neutral IR); this file is a pure
 * IR/schema -> source-text renderer with ZERO grammar parsing.
 *
 * Row/param "types" are inline PHPDoc array shapes (`array{key: type, ...}`)
 * on each generated method, not a separate reusable type-alias tier — PHP
 * has no structural array types, and PHPDoc comments are never executed,
 * so unlike Python's TypedDict there is no ordering constraint and no
 * hydration code to get wrong; a shape is just documentation over the
 * plain associative array the wire already returns (matching this SDK's
 * "records = plain arrays" rule all the way through codegen).
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

/**
 * FAIL LOUD on empty/unknown op kinds (the stale-IR lesson — an older Go
 * generator once silently defaulted an unknown kind to "search").
 *
 * @param list<array<string,mixed>> $operations
 * @return array{0: list<array<string,mixed>>, 1: list<array<string,mixed>>} [ops, batches]
 */
function validateOps(array $operations): array
{
    $ops = [];
    $batches = [];
    foreach ($operations as $o) {
        if (!empty($o['batch'])) {
            $batches[] = $o;
            continue;
        }
        $verb = $o['op'] ?? null;
        if (!$verb) {
            throw new CodegenError(
                "operation '{$o['name']}' has an empty op kind — the IR is stale or "
                . 'corrupt; re-run: synthigy-codegen gen',
            );
        }
        if (in_array($verb, MUTATE_VERBS, true)) {
            fwrite(STDERR, "skipped @{$verb} {$o['name']} — mutations are generated from "
                . "the schema; use Writes::{sync|stack|delete}<Entity>()\n");
            continue;
        }
        if (!in_array($verb, KNOWN_VERBS, true)) {
            throw new CodegenError(
                "operation '{$o['name']}': unknown op kind '{$verb}' (expected one of "
                . implode(', ', KNOWN_VERBS) . ') — the IR is stale or from a newer '
                . 'server; re-run gen / update the SDK',
            );
        }
        if ($verb === 'sql-template' && empty($o['namespace']) && empty($o['entity'])) {
            throw new CodegenError("op '{$o['name']}': a sql-template with no root entity needs @namespace");
        }
        $ops[] = $o;
    }
    return [$ops, $batches];
}

/**
 * @param array<string,mixed> $o
 * (namespace ?? entity, name) — bare names repeat across entities.
 */
function opIdentity(array $o): string
{
    if (!empty($o['batch'])) {
        return "@batch {$o['name']}";
    }
    $ns = strtolower((string)($o['namespace'] ?? $o['entity'] ?? ''));
    return "{$ns}/" . strtolower((string)($o['name'] ?? ''));
}

/**
 * Resolve a @batch member ref — a bare `name` (must be unique) or a
 * qualified `ns/name`. Hard error on unknown/ambiguous.
 *
 * @param list<array<string,mixed>> $ops
 *
 * @return array<string,mixed>
 */
function resolveMember(array $ops, string $batchName, string $ref): array
{
    $parts = explode('/', $ref, 2);
    [$ns, $nm] = count($parts) === 2 ? [strtolower($parts[0]), strtolower($parts[1])] : [null, strtolower($parts[0])];

    $matches = array_values(array_filter($ops, static function (array $o) use ($ns, $nm): bool {
        if (strtolower((string)($o['name'] ?? '')) !== $nm) {
            return false;
        }
        return $ns === null || strtolower((string)($o['namespace'] ?? $o['entity'] ?? '')) === $ns;
    }));

    if ($matches === []) {
        throw new CodegenError("@batch '{$batchName}' references unknown/ungenerated op '{$ref}'");
    }
    if ($ns === null && count($matches) > 1) {
        $ids = implode(', ', array_map(opIdentity(...), $matches));
        throw new CodegenError("@batch '{$batchName}' — '{$ref}' is ambiguous: {$ids} — qualify the reference");
    }
    return $matches[0];
}

function srcConstName(string $ns, string $name): string
{
    $ident = static fn(string $s) => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '_', $s) ?? $s);
    return '_SRC_' . $ident($ns) . '_' . $ident($name);
}

/** @param array<string,mixed> $field */
function fieldShape(array $field): string
{
    $key = (string)$field['key'];
    $kind = $field['kind'] ?? null;
    if ($kind === 'relation') {
        $child = rowShape($field['fields'] ?? []);
        $inner = (($field['cardinality'] ?? null) === 'many') ? "list<{$child}>" : $child;
        return "{$key}?: {$inner}";
    }
    if ($kind === 'map') {
        $v = scalarType((string)($field['value'] ?? ''));
        return "{$key}?: array<string, {$v}>";
    }
    return "{$key}: " . scalarFieldType($field);
}

/** @param list<array<string,mixed>> $fields */
function rowShape(array $fields): string
{
    if ($fields === []) {
        return 'array<string, mixed>';
    }
    return 'array{' . implode(', ', array_map(fieldShape(...), $fields)) . '}';
}

/** @param list<array<string,mixed>>|null $params */
function paramShape(?array $params): string
{
    if (!$params) {
        return 'array<never, never>';
    }
    $entries = [];
    foreach ($params as $p) {
        $t = scalarType((string)($p['type'] ?? ''));
        if (!empty($p['array'])) {
            $t = "list<{$t}>";
        }
        $entries[] = (!empty($p['optional']) ? "{$p['name']}?: " : "{$p['name']}: ") . $t;
    }
    return 'array{' . implode(', ', $entries) . '}';
}

function phpStringLiteral(string $s): string
{
    return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $s) . "'";
}

/**
 * @param array<string,mixed> $op
 * @param array<string,mixed>|null $schema
 * @return array{0: string, 1: string}
 * One non-batch op -> [sourceConstDecl, methodSourceText].
 */
function emitOp(array $op, ?array $schema): array
{
    $ns = (string)($op['namespace'] ?? $op['entity']);
    $name = (string)$op['name'];
    $const = srcConstName($ns, $name);
    $doc = $op['op'] === 'sql-template' ? (string)($op['source'] ?? '') : "@{$op['op']} {$name}\n" . (string)($op['source'] ?? '');
    $srcDecl = "const {$const} = " . phpStringLiteral($doc) . ';';

    $params = $op['params'] ?? null;
    $hasParams = !empty($params);
    $paramsType = $hasParams ? paramShape($params) : null;

    $resultFields = $op['result']['fields'] ?? [];
    $rowType = $resultFields ? rowShape($resultFields) : 'array<string, mixed>';

    $methodName = camel($name);
    $paramsSig = $hasParams ? 'array $params = [], ' : '';
    $paramsArg = $hasParams ? '$params' : '[]';

    $doclines = [];
    if ($paramsType !== null) {
        $doclines[] = " * @param {$paramsType} \$params";
    }

    // sql-template ops are NOT XSQL documents on the wire — their `source`
    // is raw SQL (ERD {entity.field} placeholders, no "@verb name" header),
    // compiled through the separate `op:"sql-template"` primitive
    // (Client::sqlTemplate), never `op:"xsql"`. Routing them through
    // query()/opQuery() (which wraps a headerless body as "@search _q\n"
    // + the source) sends raw SQL to the XSQL parser and fails with
    // XSQL_PARSE_ERROR — caught live against the real server, not by unit
    // tests alone. Named `?name:type` placeholders bind from an
    // associative $params array (encodes as a JSON object), same as XSQL.
    return match ($op['op']) {
        'get' => [$srcDecl, emitMethod($methodName, $paramsSig, [...$doclines, " * @return {$rowType}|null"], '?array',
            "\$data = \\Synthigy\\Synthigy::query({$const}, {$paramsArg}, actingAs: \$actingAs);\n        return is_array(\$data) ? \$data : null;")],
        'slice' => [$srcDecl, emitMethod($methodName, $paramsSig, [...$doclines, ' * @return array<string, bool>'], 'array',
            "\$data = \\Synthigy\\Synthigy::query({$const}, {$paramsArg}, actingAs: \$actingAs);\n        return is_array(\$data) ? \$data : [];")],
        'sql-template' => [$srcDecl, emitMethod($methodName, $paramsSig, [...$doclines, " * @return list<{$rowType}>"], 'array',
            "\$data = \\Synthigy\\Synthigy::sqlTemplate({$const}, {$paramsArg}, actingAs: \$actingAs);\n        return is_array(\$data) ? array_values(\$data) : [];")],
        default => [$srcDecl, emitMethod($methodName, $paramsSig, [...$doclines, " * @return list<{$rowType}>"], 'array',
            "\$data = \\Synthigy\\Synthigy::query({$const}, {$paramsArg}, actingAs: \$actingAs);\n        return is_array(\$data) ? array_values(\$data) : [];")],
    };
}

/** @param list<string> $docLines */
function emitMethod(string $methodName, string $paramsSig, array $docLines, string $returnType, string $body): string
{
    $doc = $docLines === [] ? '' : ("    /**\n" . implode("\n", array_map(static fn($l) => "    {$l}", $docLines)) . "\n     */\n");
    return "{$doc}    public static function {$methodName}({$paramsSig}?string \$actingAs = null): {$returnType}\n"
        . "    {\n        {$body}\n    }";
}

/**
 * @param array<string,mixed> $batch
 * @param list<array<string,mixed>> $ops
 * One @batch op -> the batch's method source text (no separate consts; members reuse their own).
 */
function emitBatch(array $batch, array $ops): string
{
    $name = safeIdent((string)$batch['name']);
    $members = array_map(static fn($ref) => resolveMember($ops, (string)$batch['name'], (string)$ref), $batch['members'] ?? []);

    $names = array_map(static fn($m) => strtolower((string)$m['name']), $members);
    $counts = array_count_values($names);
    $keyFor = static function (array $m) use ($counts): string {
        return $counts[strtolower((string)$m['name'])] > 1 ? opIdentity($m) : (string)$m['name'];
    };

    $opLines = [];
    $resLines = [];
    foreach ($members as $i => $m) {
        $ns = (string)($m['namespace'] ?? $m['entity']);
        $const = srcConstName($ns, (string)$m['name']);
        // sql-template members go through the raw sql-template wire op
        // (their `source` is SQL, not an XSQL document) — see the same
        // note on emitOp above; opQuery() would send it to the XSQL
        // parser and fail with XSQL_PARSE_ERROR.
        $builder = $m['op'] === 'sql-template' ? 'opSqlTemplate' : 'opQuery';
        $opLines[] = "            \\Synthigy\\{$builder}({$const}, \$params),";
        $resLines[] = '            ' . phpStringLiteral($keyFor($m)) . " => \\Synthigy\\batchResult(\$results[{$i}] ?? [], " . phpStringLiteral((string)$m['op']) . '),';
    }

    $memberList = implode(' + ', $batch['members'] ?? []);
    $opBlock = implode("\n", $opLines);
    $resBlock = implode("\n", $resLines);

    return "    /**\n"
        . "     * Batch: {$memberList} — ONE wire request; results keyed by member\n"
        . "     * name. A failed member's value is a SynthigyError instance, never\n"
        . "     * a raised batch-wide error.\n"
        . "     *\n"
        . "     * @param array<string,mixed> \$params forwarded to every member's query verbatim (v1: no per-member param merge/dedupe)\n"
        . "     * @return array<string, mixed>\n"
        . "     */\n"
        . "    public static function {$name}(array \$params = [], ?string \$actingAs = null): array\n"
        . "    {\n"
        . "        \$operations = [\n{$opBlock}\n        ];\n"
        . "        \$results = \\Synthigy\\Synthigy::exec(\$operations, actingAs: \$actingAs);\n"
        . "        return [\n{$resBlock}\n        ];\n"
        . "    }";
}

// ── write tier (schema-derived, never from the IR) ──────────────────────

/**
 * Entities the operations touch: op roots, entity-named @namespaces, and
 * every relation target reachable through the IR result trees (resolved
 * via the schema — a relation whose target isn't in the schema is
 * skipped).
 *
 * @param list<array<string,mixed>> $ops
 * @param array<string,mixed> $schema
 * @return list<string>
 */
function referencedEntities(array $ops, array $schema): array
{
    $entities = $schema['entities'] ?? [];
    $refs = [];

    $walk = function (string $entity, array $fields) use (&$walk, &$refs, $entities): void {
        if (!isset($entities[$entity])) {
            return;
        }
        $refs[$entity] = true;
        $rels = $entities[$entity]['relations'] ?? [];
        foreach ($fields as $f) {
            if (($f['kind'] ?? null) === 'relation') {
                $rel = $rels[$f['key']] ?? null;
                if ($rel && isset($entities[$rel['to']])) {
                    $walk($rel['to'], $f['fields'] ?? []);
                }
            }
        }
    };

    foreach ($ops as $o) {
        $ent = $o['entity'] ?? null;
        if ($ent) {
            $walk($ent, $o['result']['fields'] ?? []);
        }
        $ns = $o['namespace'] ?? null;
        if ($ns && isset($entities[$ns])) {
            $refs[$ns] = true;
        }
    }

    $out = array_keys($refs);
    sort($out);
    return $out;
}

/**
 * @param array<string,mixed> $attr
 * Schema attribute -> PHP write-input scalar type. `hashed` is write-only -> plain string on input.
 */
function attrWriteType(array $attr): string
{
    $t = $attr['type'] ?? '';
    if ($t === 'enum' && !empty($attr['enum'])) {
        return implode('|', array_map(static fn($v) => "'" . str_replace("'", "\\'", (string)$v) . "'", $attr['enum']));
    }
    if ($t === 'hashed') {
        return 'string';
    }
    return SCALAR_TYPES[$t] ?? 'string';
}

/**
 * @param array<string,mixed> $schema
 * @param list<string> $referenced
 * @return array{0: string, 1: string} [inputShapeDoc, writesClassMethods]
 */
function emitWriteEntity(string $entity, array $schema, array $referenced): array
{
    $ent = $schema['entities'][$entity];
    $E = entityPascal($schema, $entity);
    $rels = $ent['relations'] ?? [];
    $entries = ['xid?: string'];
    foreach ($ent['attributes'] ?? [] as $k => $a) {
        if ($k === 'xid' || isset($rels[$k])) {
            continue;
        }
        $t = attrWriteType($a);
        $entries[] = (($a['nullable'] ?? null) === false) ? "{$k}: {$t}" : "{$k}?: {$t}|null";
    }
    foreach ($rels as $k => $r) {
        if (!in_array($r['to'] ?? null, array_keys($schema['entities'] ?? []), true)) {
            continue;
        }
        $link = in_array($r['to'], $referenced, true)
            ? '(array{xid: string}|' . entityPascal($schema, $r['to']) . 'Input)'
            : 'array<string, mixed>';
        $inner = (($r['cardinality'] ?? null) === 'many') ? "list<{$link}>" : $link;
        $entries[] = "{$k}?: {$inner}";
    }
    $inputShape = "@phpstan-type {$E}Input array{" . implode(', ', $entries) . '}';

    $lit = phpStringLiteral($entity);
    $methods = "    /**\n"
        . "     * Upsert one {$entity} record — REPLACES relation link-sets.\n"
        . "     *\n"
        . "     * Silent by default: answers ['count' => n]. Pass \$returning for the\n"
        . "     * written record, or mint the id up front with \\Synthigy\\newXid().\n"
        . "     *\n"
        . "     * @param {$E}Input \$data\n"
        . "     * @return array<string, mixed>\n"
        . "     */\n"
        . "    public static function sync{$E}(array \$data, ?string \$actingAs = null, bool \$returning = false): array\n"
        . "    {\n        return \\Synthigy\\Synthigy::sync({$lit}, \$data, \$actingAs, null, \$returning);\n    }\n\n"
        . "    /**\n"
        . "     * Additive write to one {$entity} record — relation links are ADDED, never removed.\n"
        . "     * Same \$returning contract as sync{$E}.\n"
        . "     *\n"
        . "     * @param {$E}Input \$data\n"
        . "     * @return array<string, mixed>\n"
        . "     */\n"
        . "    public static function stack{$E}(array \$data, ?string \$actingAs = null, bool \$returning = false): array\n"
        . "    {\n        return \\Synthigy\\Synthigy::stack({$lit}, \$data, \$actingAs, null, \$returning);\n    }\n\n"
        . "    /** Soft-delete one {$entity} record by xid. */\n"
        . "    public static function delete{$E}(string \$xid, ?string \$actingAs = null): bool\n"
        . "    {\n        return \\Synthigy\\Synthigy::delete({$lit}, ['xid' => \$xid], \$actingAs);\n    }";

    return [$inputShape, $methods];
}

// ── top-level assembly ───────────────────────────────────────────────────

/**
 * IR (+ schema for the write tier) -> the full generated PHP source text.
 *
 * @param array<string,mixed> $ir
 * @param array<string,mixed>|null $schema
 */
function render(array $ir, ?array $schema, bool $writes, string $namespace, string $inputName): string
{
    if ($writes && $schema === null) {
        throw new CodegenError(
            'write tier needs a schema — pass --schema, keep a schema.json beside the .xsql, or use --no-writes',
        );
    }

    [$ops, $batches] = validateOps($ir['operations'] ?? []);

    $seen = [];
    foreach ($ops as $o) {
        $oid = opIdentity($o);
        if (isset($seen[$oid])) {
            throw new CodegenError("duplicate operation '{$oid}' — (namespace, name) must be unique");
        }
        $seen[$oid] = true;
    }

    $srcChunks = [];
    $classesByNs = [];
    foreach ($ops as $o) {
        $nsRaw = (string)($o['namespace'] ?? $o['entity']);
        $NS = entityPascal($schema, $nsRaw);
        [$srcDecl, $method] = emitOp($o, $schema);
        $srcChunks[] = $srcDecl;
        $classesByNs[$NS] ??= ['ns' => $nsRaw, 'methods' => []];
        $classesByNs[$NS]['methods'][] = $method;
    }
    ksort($classesByNs);

    $classChunks = [];
    foreach ($classesByNs as $NS => $g) {
        $body = implode("\n\n", $g['methods']);
        $classChunks[] = "/** Typed operations for XSQL namespace '{$g['ns']}'. */\nfinal class {$NS}\n{\n{$body}\n}";
    }

    if ($batches !== []) {
        $batchMethods = array_map(static fn($b) => emitBatch($b, $ops), $batches);
        $classChunks[] = "final class Batches\n{\n" . implode("\n\n", $batchMethods) . "\n}";
    }

    if ($writes) {
        $referenced = referencedEntities($ops, $schema);
        if ($referenced !== []) {
            $aliasLines = [];
            $writeMethods = [];
            foreach ($referenced as $e) {
                [$aliasLine, $methods] = emitWriteEntity($e, $schema, $referenced);
                $aliasLines[] = " * {$aliasLine}";
                $writeMethods[] = $methods;
            }
            $doc = "/**\n" . implode("\n", $aliasLines) . "\n */\n";
            $classChunks[] = $doc . "final class Writes\n{\n" . implode("\n\n", $writeMethods) . "\n}";
        }
    }

    $schemaV = $schema['version'] ?? null;
    $header = "<?php\n\n"
        . "/**\n"
        . " * AUTO-GENERATED by synthigy-codegen from {$inputName} — DO NOT EDIT.\n"
        . ' * sourceHash: ' . ($ir['sourceHash'] ?? '') . "\n"
        . (($writes && $schemaV) ? " * schema: /schema @{$schemaV}\n" : '')
        . " * Regenerate: bin/synthigy-codegen gen {$inputName}\n"
        . " *\n"
        . " * Data keys are snake_case verbatim — server-native, no casing transform.\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . "namespace {$namespace};\n\n";

    $parts = [rtrim($header)];
    if ($srcChunks !== []) {
        $parts[] = implode("\n", $srcChunks);
    }
    $parts = [...$parts, ...$classChunks];
    return implode("\n\n", $parts) . "\n";
}
