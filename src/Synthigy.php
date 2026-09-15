<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Static facade over a process-wide default Client — one process, one
 * client, one backend. Synthigy::connect() installs the default; every
 * static verb below delegates to it. Identity is multiplexed per-call via
 * $actingAs, never a second connect(). Constructing Client directly is the
 * escape hatch (tests, or a genuine multi-endpoint script).
 *
 *   Synthigy::connect('https://synthigy.example.com',
 *       clientId: 'my-service', clientSecret: getenv('SYNTHIGY_SECRET'));
 *
 *   $users = Synthigy::search('User',
 *       ['active' => eq(true), '_limit' => 10],
 *       ['name' => null, 'email' => null],
 *       actingAs: $userXid);
 */
final class Synthigy
{
    private static ?Client $default = null;

    private function __construct()
    {
    }

    /**
     * Create a Client and install it as the process default. No previous
     * default is torn down beyond dropping the reference — Client (CRUD +
     * auth only) holds no live connection to close.
     */
    public static function connect(
        string $endpoint,
        ?string $token = null,
        ?string $clientId = null,
        ?string $clientSecret = null,
        ?string $scope = null,
        ?string $actingAs = null,
        ?string $keyFormat = null,
        ?float $timeoutSeconds = null,
        ?string $audience = null,
    ): Client {
        return self::$default = new Client(
            endpoint: $endpoint,
            token: $token,
            clientId: $clientId,
            clientSecret: $clientSecret,
            scope: $scope,
            actingAs: $actingAs,
            keyFormat: $keyFormat,
            timeoutSeconds: $timeoutSeconds,
            audience: $audience,
        );
    }

    public static function disconnect(): void
    {
        self::$default = null;
    }

    /** @throws SynthigyError NOT_CONNECTED when connect() hasn't been called */
    public static function client(): Client
    {
        return self::$default ?? throw new SynthigyError(
            'not connected — call Synthigy::connect() first',
            'NOT_CONNECTED',
        );
    }

    // ── CRUD + XSQL ──────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|null $args
     * @return list<array<string,mixed>>
     */
    public static function search(string $entity, ?array $args = null, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->search($entity, $args, $selection, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,mixed>|null
     */
    public static function get(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): ?array
    {
        return self::client()->get($entity, $args, $selection, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function sync(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null, bool $returning = false): array
    {
        return self::client()->sync($entity, $data, $actingAs, $keyFormat, $returning);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function stack(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null, bool $returning = false): array
    {
        return self::client()->stack($entity, $data, $actingAs, $keyFormat, $returning);
    }

    /**
     * @param array<string,mixed> $args
     * @return array<string,bool>
     */
    public static function slice(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->slice($entity, $args, $selection, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function delete(string $entity, array $data, ?string $actingAs = null, ?string $keyFormat = null): bool
    {
        return self::client()->delete($entity, $data, $actingAs, $keyFormat);
    }

    /** @return array<string,mixed> */
    public static function deploy(string $exportContents, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->deploy($exportContents, $actingAs, $keyFormat);
    }

    public static function destroy(string $datasetXid, ?string $actingAs = null, ?string $keyFormat = null): bool
    {
        return self::client()->destroy($datasetXid, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed> $args
     * @return list<array<string,mixed>>
     */
    public static function purge(string $entity, array $args, mixed $selection = null, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->purge($entity, $args, $selection, $actingAs, $keyFormat);
    }

    /**
     * @param array<array-key,mixed> $params
     * @return list<array<string,mixed>>
     */
    public static function sqlTemplate(string $template, array $params = [], ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->sqlTemplate($template, $params, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed>|null $params
     */
    public static function query(string $xsql, ?array $params = null, string $op = 'search', ?string $actingAs = null, ?string $keyFormat = null): mixed
    {
        return self::client()->query($xsql, $params, $op, $actingAs, $keyFormat);
    }

    /**
     * @param array<string,mixed>|null $args
     * @return list<array<string,mixed>>
     */
    public static function searchTree(string $entity, string $on, ?array $args = null, mixed $selection = null, bool $raw = false, string $childrenKey = '_children', ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->searchTree($entity, $on, $args, $selection, $raw, $childrenKey, $actingAs, $keyFormat);
    }

    public static function getTree(string $entity, mixed $root, string $on, mixed $selection = null, bool $raw = false, string $childrenKey = '_children', ?string $actingAs = null, ?string $keyFormat = null): mixed
    {
        return self::client()->getTree($entity, $root, $on, $selection, $raw, $childrenKey, $actingAs, $keyFormat);
    }

    /**
     * @param list<array<string,mixed>> $operations
     * @return list<array<string,mixed>>
     */
    public static function exec(array $operations, ?string $actingAs = null, ?string $keyFormat = null): array
    {
        return self::client()->exec($operations, $actingAs, $keyFormat);
    }

    // ── Introspection ────────────────────────────────────────────────

    /**
     * @param list<string>|null $entities
     * @return array<string,mixed>
     */
    public static function schema(?array $entities = null): array
    {
        return self::client()->schema($entities);
    }

    /** @return list<array<string,mixed>> */
    public static function lint(string $source, ?string $entity = null, ?string $op = null): array
    {
        return self::client()->lint($source, $entity, $op);
    }

    /** @return array<string,mixed> */
    public static function deployedModel(): array
    {
        return self::client()->deployedModel();
    }

    /** @return array<string,mixed> */
    public static function runtimeModel(): array
    {
        return self::client()->runtimeModel();
    }

    /**
     * @param list<string>|null $methods
     * @return array<string,mixed>
     */
    public static function onboard(string $xid, ?bool $reset = null, ?array $methods = null, ?int $ttlSeconds = null, ?string $returnUrl = null): array
    {
        return self::client()->onboard($xid, $reset, $methods, $ttlSeconds, $returnUrl);
    }

    /** @return array<string,mixed> */
    public static function onboardComplete(string $ticket): array
    {
        return self::client()->onboardComplete($ticket);
    }

    public static function token(?string $audience = null): string
    {
        return self::client()->token($audience);
    }
}
