<?php

/**
 * Shared naming/type-mapping helpers — the PHP analog of sdk/py's
 * codegen.py `_words`/`pascal`/`safe_ident`/`PY_SCALAR`, and of
 * sdk/js/codegen/lib.mjs.
 */

declare(strict_types=1);

namespace Synthigy\Codegen;

/** wire scalar type -> PHP type expression (timestamps are ISO strings on the wire). */
const SCALAR_TYPES = [
    'int' => 'int', 'float' => 'float', 'number' => 'float', 'string' => 'string',
    'boolean' => 'bool', 'timestamp' => 'string', 'uuid' => 'string', 'json' => 'mixed',
    'transit' => 'mixed', 'encrypted' => 'string', 'order' => 'string',
];

const READ_VERBS = ['search', 'get', 'slice', 'purge'];
const KNOWN_VERBS = ['search', 'get', 'slice', 'purge', 'sql-template'];
const MUTATE_VERBS = ['sync', 'stack', 'delete'];

/** @return list<string> */
function words(string $s): array
{
    $out = [];
    $cur = '';
    foreach (str_split($s) as $ch) {
        if ($ch === '-' || $ch === '_' || $ch === ' ') {
            if ($cur !== '') {
                $out[] = $cur;
            }
            $cur = '';
        } else {
            $cur .= $ch;
        }
    }
    if ($cur !== '') {
        $out[] = $cur;
    }
    return $out;
}

function pascal(string $s): string
{
    $out = '';
    foreach (words($s) as $w) {
        $out .= mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
    }
    return $out;
}

function camel(string $s): string
{
    $p = pascal($s);
    return $p === '' ? '' : (mb_strtolower(mb_substr($p, 0, 1)) . mb_substr($p, 1));
}

/**
 * Server-rendered pascal skin (docs/plans/PLAN-SCHEMA-SKINS-PROJECTION.md)
 * for schema.entities[$name], falling back to the local guess.
 *
 * @param array<string,mixed>|null $schema
 */
function entityPascal(?array $schema, string $name): string
{
    $ent = $schema['entities'][$name] ?? [];
    return $ent['skins']['pascal'] ?? pascal($name);
}

/** A valid PHP identifier for a wire name — PHP has no bare-word keyword clash
 * risk for method names the way Python does (methods are always called as
 * $x->name(), never a bare identifier), so no rename is needed; kept as a
 * named function for symmetry with the other SDKs' codegen and as the one
 * seam if a wire name ever needs escaping (e.g. a leading digit). */
function safeIdent(string $s): string
{
    return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $s) === 1 ? $s : ('_' . preg_replace('/[^A-Za-z0-9_]/', '_', $s));
}

/** IR result-field / write-attribute wire type -> PHP type expression. */
function scalarType(string $wireType): string
{
    return SCALAR_TYPES[$wireType] ?? 'mixed';
}

/**
 * IR field -> phpstan array-shape type expression. `nullable: false` means
 * non-null; anything else (including absent) is nullable, matching the
 * conservative default the other SDKs use.
 *
 * @param array<string,mixed> $field
 */
function scalarFieldType(array $field): string
{
    if (!empty($field['enum'])) {
        $lits = array_map(static fn($v) => "'" . str_replace("'", "\\'", (string)$v) . "'", $field['enum']);
        $base = implode('|', $lits);
    } else {
        $base = scalarType((string)($field['type'] ?? ''));
    }
    return ($field['nullable'] ?? null) === false ? $base : "{$base}|null";
}
