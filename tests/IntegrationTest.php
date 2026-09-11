<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\SynthigyError;

use function Synthigy\eq;
use function Synthigy\gt;

/**
 * Live suite — talks to a real Synthigy server. Excluded from the default
 * run (phpunit.xml.dist excludes @group live); `composer test:live` opts in.
 *
 * Env:
 *   SYNTHIGY_TEST_ENDPOINT       default http://localhost:7887
 *   SYNTHIGY_TEST_CLIENT_ID      + _SECRET  (client_credentials), or
 *   SYNTHIGY_TEST_TOKEN          a pre-minted bearer token
 *   SYNTHIGY_TEST_AUDIENCE       the /data audience, when the server needs
 *                                one explicitly (this platform does — a mint
 *                                with no audience yields an identity-only
 *                                token that 401s on /data, by design)
 *   SYNTHIGY_TEST_ENTITY         entity to read, default "movie"
 *
 * Register a DEDICATED OAuth client for this — never share identity with a
 * live app. Everything here is read-only except one write round trip that
 * creates and then deletes its own record.
 */
#[Group('live')]
final class IntegrationTest extends TestCase
{
    private static ?Client $client = null;
    private static string $entity = 'movie';

    public static function setUpBeforeClass(): void
    {
        $endpoint = getenv('SYNTHIGY_TEST_ENDPOINT') ?: 'http://localhost:7887';
        $token = getenv('SYNTHIGY_TEST_TOKEN') ?: null;
        $clientId = getenv('SYNTHIGY_TEST_CLIENT_ID') ?: null;
        $clientSecret = getenv('SYNTHIGY_TEST_CLIENT_SECRET') ?: null;
        $audience = getenv('SYNTHIGY_TEST_AUDIENCE') ?: null;
        self::$entity = getenv('SYNTHIGY_TEST_ENTITY') ?: 'movie';

        if ($token === null && ($clientId === null || $clientSecret === null)) {
            return; // markTestSkipped per test — setUpBeforeClass can't skip cleanly
        }

        self::$client = new Client(
            endpoint: $endpoint,
            token: $token,
            clientId: $clientId,
            clientSecret: $clientSecret,
            audience: $audience,
        );
    }

    private function client(): Client
    {
        if (self::$client === null) {
            self::markTestSkipped(
                'live suite needs SYNTHIGY_TEST_TOKEN, or SYNTHIGY_TEST_CLIENT_ID + '
                . 'SYNTHIGY_TEST_CLIENT_SECRET (+ SYNTHIGY_TEST_AUDIENCE if the server requires one)',
            );
        }
        return self::$client;
    }

    /** A 22-char client-minted xid — this platform expects the client to mint its own on insert. */
    private static function mintXid(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
        $xid = '';
        for ($i = 0; $i < 22; $i++) {
            $xid .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $xid;
    }

    public function testTokenMintSucceeds(): void
    {
        $token = $this->client()->token();
        self::assertNotSame('', $token, 'expected a non-empty bearer token');
    }

    public function testSchemaReturnsEntities(): void
    {
        $schema = $this->client()->schema();

        self::assertArrayHasKey('entities', $schema);
        self::assertNotEmpty($schema['entities']);
        self::assertArrayHasKey(
            self::$entity,
            $schema['entities'],
            sprintf('entity "%s" not in the IAM-filtered schema', self::$entity),
        );
    }

    public function testSearchReturnsAListOfRecords(): void
    {
        $rows = $this->client()->search(self::$entity, ['_limit' => 3], ['xid' => null]);

        self::assertLessThanOrEqual(3, count($rows));
        foreach ($rows as $row) {
            self::assertArrayHasKey('xid', $row);
        }
    }

    public function testGetByXidRoundTripsAndMissingReturnsNull(): void
    {
        $rows = $this->client()->search(self::$entity, ['_limit' => 1], ['xid' => null]);
        if ($rows === []) {
            self::markTestSkipped(sprintf('no %s rows on this server to read back', self::$entity));
        }

        $one = $this->client()->get(self::$entity, ['xid' => $rows[0]['xid']], ['xid' => null]);
        self::assertNotNull($one);
        self::assertSame($rows[0]['xid'], $one['xid']);

        self::assertNull(
            $this->client()->get(self::$entity, ['xid' => 'definitely-not-a-real-xid'], ['xid' => null]),
            'get() must return null for a miss, not throw',
        );
    }

    public function testSqlTemplateCounts(): void
    {
        $rows = $this->client()->sqlTemplate(
            sprintf('SELECT count(*) AS n FROM {%s}', self::$entity),
        );

        self::assertCount(1, $rows);
        self::assertArrayHasKey('n', $rows[0]);
        self::assertIsInt($rows[0]['n']);
    }

    public function testXsqlQuery(): void
    {
        $rows = $this->client()->query(sprintf("%s (_limit 2)\n  xid", self::$entity));

        self::assertIsArray($rows);
        self::assertLessThanOrEqual(2, count($rows));
    }

    public function testExecRunsHeterogeneousOpsInOneRoundTrip(): void
    {
        $results = $this->client()->exec([
            \Synthigy\opSearch(self::$entity, ['_limit' => 1], ['xid' => null]),
            \Synthigy\opSqlTemplate(sprintf('SELECT count(*) AS n FROM {%s}', self::$entity)),
        ]);

        self::assertCount(2, $results);
        foreach ($results as $r) {
            self::assertTrue($r['ok'] ?? false, 'every batched op should succeed');
            self::assertArrayHasKey('_request_id', $r, 'request id must be threaded onto each result');
        }
        // One round trip => one request id shared by every result.
        self::assertSame($results[0]['_request_id'], $results[1]['_request_id']);
    }

    public function testOperatorsFilterServerSide(): void
    {
        $schema = $this->client()->schema([self::$entity]);
        $attrs = array_keys($schema['entities'][self::$entity]['attributes'] ?? []);
        if (!in_array('release_year', $attrs, true)) {
            self::markTestSkipped('no release_year attribute to filter on');
        }

        $rows = $this->client()->search(
            self::$entity,
            ['release_year' => gt(1900), '_limit' => 2],
            ['release_year' => null],
        );
        foreach ($rows as $row) {
            self::assertGreaterThan(1900, $row['release_year']);
        }
    }

    public function testUnknownEntityRaisesTypedError(): void
    {
        try {
            $this->client()->search('definitely_not_an_entity_xyz', ['_limit' => 1]);
            self::fail('expected a SynthigyError for an unknown entity');
        } catch (SynthigyError $e) {
            self::assertNotSame('', $e->code);
            self::assertContains(
                $e->category,
                ['not_found', 'validation', 'iam'],
                "unexpected category {$e->category} for code {$e->code}",
            );
        }
    }

    public function testWriteReadDeleteRoundTrip(): void
    {
        $schema = $this->client()->schema([self::$entity]);
        $attrs = array_keys($schema['entities'][self::$entity]['attributes'] ?? []);
        if (!in_array('title', $attrs, true)) {
            self::markTestSkipped('no title attribute to write');
        }

        $xid = self::mintXid();
        $title = 'php-sdk live test ' . $xid;

        $this->client()->sync(self::$entity, ['xid' => $xid, 'title' => $title]);

        try {
            $read = $this->client()->get(self::$entity, ['xid' => $xid], ['xid' => null, 'title' => null]);
            self::assertNotNull($read, 'record should be readable straight after sync');
            self::assertSame($title, $read['title']);
        } finally {
            // Always clean up, even if the assertions above failed.
            $this->client()->delete(self::$entity, ['xid' => $xid]);
        }

        self::assertNull(
            $this->client()->get(self::$entity, ['xid' => $xid], ['xid' => null]),
            'record should be gone after delete',
        );
    }
}
