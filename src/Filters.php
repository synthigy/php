<?php

/**
 * Where-clause operators — plain array builders (data-shaped, no DSL).
 *
 * `and`/`or`/`not` are reserved words in PHP (low-precedence logical
 * operators), so — same reason as the Python SDK — they get a trailing
 * underscore: and_/or_/not_. in_/nin follow suit for consistency.
 *
 * Wire mapping note: gte -> _ge, lte -> _le (not _gte/_lte).
 */

declare(strict_types=1);

namespace Synthigy;

/** @return array{_eq: mixed} */
function eq(mixed $v): array
{
    return ['_eq' => $v];
}

/** @return array{_neq: mixed} */
function neq(mixed $v): array
{
    return ['_neq' => $v];
}

/** @return array{_gt: mixed} */
function gt(mixed $v): array
{
    return ['_gt' => $v];
}

/** @return array{_ge: mixed} */
function gte(mixed $v): array
{
    return ['_ge' => $v];
}

/** @return array{_lt: mixed} */
function lt(mixed $v): array
{
    return ['_lt' => $v];
}

/** @return array{_le: mixed} */
function lte(mixed $v): array
{
    return ['_le' => $v];
}

/** @return array{_like: string} */
function like(string $v): array
{
    return ['_like' => $v];
}

/** @return array{_ilike: string} */
function ilike(string $v): array
{
    return ['_ilike' => $v];
}

/** @return array{_is_null: true} */
function isNull(): array
{
    return ['_is_null' => true];
}

/** @return array{_is_not_null: true} */
function isNotNull(): array
{
    return ['_is_not_null' => true];
}

/**
 * in_(1, 2, 3) or in_([1, 2, 3]) — variadic args are flattened one level,
 * mirroring the JS SDK's `v.flat()` / the Python SDK's `_flat`.
 *
 * @return array{_in: list<mixed>}
 */
function in_(mixed ...$values): array
{
    return ['_in' => flattenOne($values)];
}

/** @return array{_nin: list<mixed>} */
function nin(mixed ...$values): array
{
    return ['_nin' => flattenOne($values)];
}

/**
 * @param array<string,mixed>|list<array<string,mixed>> $clauses
 * @return array{_and: list<array<string,mixed>>}
 */
function and_(array ...$clauses): array
{
    return ['_and' => flattenOne($clauses)];
}

/**
 * @param array<string,mixed>|list<array<string,mixed>> $clauses
 * @return array{_or: list<array<string,mixed>>}
 */
function or_(array ...$clauses): array
{
    return ['_or' => flattenOne($clauses)];
}

/**
 * @param array<string,mixed> $clause
 * @return array{_not: array<string,mixed>}
 */
function not_(array $clause): array
{
    return ['_not' => $clause];
}

/**
 * One-level flatten: a bare list passed as a single element is spread, so
 * both in_(1, 2, 3) / and_($c1, $c2) and in_([1, 2, 3]) / and_([$c1, $c2])
 * work. A clause is itself an associative array (string keys like "_eq"),
 * never a sequential list, so array_is_list() is what tells "a list of
 * items to spread" apart from "one clause/value to keep whole" — the PHP
 * analog of Python's `isinstance(v, (list, tuple, set))` vs dict check.
 *
 * @param array<array-key, mixed> $values
 * @return list<mixed>
 */
function flattenOne(array $values): array
{
    $out = [];
    foreach ($values as $v) {
        if (is_array($v) && array_is_list($v)) {
            foreach ($v as $inner) {
                $out[] = $inner;
            }
        } else {
            $out[] = $v;
        }
    }
    return $out;
}
