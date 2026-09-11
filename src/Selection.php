<?php

/**
 * Selection normalization — the load-bearing wire transform.
 *
 * Shorthands (mixable at any nesting level):
 *   null                    -> null (include scalar)
 *   ["name", "email"]       -> ["name" => null, "email" => null]
 *   ["roles" => ["name" => null]]
 *                          -> ["roles" => [["selections" => ["name" => null]]]]
 *   ["roles" => [rel(...), rel(...)]]  -> passthrough, nested normalized
 *
 * Join semantics are the SERVER's: absent "_join" is LEFT (a selection is a
 * projection and never drops parents; relation args filter the related
 * rows). The client injects nothing — pass ["_join" => "inner"] explicitly
 * when the relation's existence should scope its parent.
 */

declare(strict_types=1);

namespace Synthigy;

/**
 * Relation config for args / alias / repeated relations.
 *
 * @param mixed $selections
 * @param array<string,mixed>|null $args
 * @return array<string,mixed>
 */
function rel(mixed $selections, ?array $args = null, ?string $alias = null): array
{
    $config = ['selections' => normalizeSelection($selections)];
    if ($args) {
        $config['args'] = $args;
    }
    if ($alias !== null) {
        $config['alias'] = $alias;
    }
    return $config;
}

/**
 * fields("a", "b") -> ["a" => null, "b" => null]
 *
 * @return array<string,null>
 */
function fields(string ...$names): array
{
    $out = [];
    foreach ($names as $n) {
        $out[$n] = null;
    }
    return $out;
}

/**
 * @param array<string,mixed> $config
 * @return array<string,mixed>
 */
function normalizeRelConfig(array $config): array
{
    $out = $config;
    if (array_key_exists('selections', $config)) {
        $out['selections'] = normalizeSelection($config['selections']);
    }
    return $out;
}

function normalizeSelection(mixed $selection): mixed
{
    if ($selection === null || $selection === true) {
        return null;
    }

    if (is_array($selection)) {
        if (array_is_list($selection)) {
            if ($selection !== [] && is_string($selection[0])) {
                return fields(...$selection);
            }
            return array_map(
                static fn(mixed $c): mixed => is_array($c) ? normalizeRelConfig($c) : $c,
                $selection,
            );
        }

        $normalized = [];
        foreach ($selection as $key => $value) {
            if ($value === null || $value === true) {
                $normalized[$key] = null;
            } elseif (is_array($value) && array_is_list($value)) {
                if ($value !== [] && is_string($value[0])) {
                    $normalized[$key] = [['selections' => fields(...$value)]];
                } else {
                    $normalized[$key] = array_map(
                        static fn(mixed $c): mixed => is_array($c) ? normalizeRelConfig($c) : $c,
                        $value,
                    );
                }
            } elseif (is_array($value)) {
                $normalized[$key] = array_key_exists('selections', $value)
                    ? [normalizeRelConfig($value)]
                    : [['selections' => normalizeSelection($value)]];
            } else {
                $normalized[$key] = $value;
            }
        }
        return $normalized;
    }

    return $selection;
}
