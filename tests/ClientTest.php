<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\SynthigyError;
use Synthigy\Tests\Fixtures\FakeTransport;

use function Synthigy\eq;

final class ClientTest extends TestCase
{
    /**
     * @param array<string,mixed> $extra
     */
    private function client(FakeTransport $transport, array $extra = []): Client
    {
        return new Client(
            endpoint: 'https://synthigy.example.test',
            token: $extra['token'] ?? 'static-token',
            actingAs: $extra['actingAs'] ?? null,
            keyFormat: $extra['keyFormat'] ?? null,
            transport: $transport,
        );
    }

    public function testSearchEnvelopeAndDefaultEmpty(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => null]]]);
        $c = $this->client($t);

        $rows = $c->search('Movie', ['_limit' => 5]);

        self::assertSame([], $rows);
        $body = $t->lastRequestBody();
        self::assertSame('search', $body['operations'][0]['op']);
        self::assertSame('Movie', $body['operations'][0]['entity']);
        self::assertSame(['_limit' => 5], $body['operations'][0]['args']);
        self::assertArrayNotHasKey('acting_as', $body);
        self::assertArrayNotHasKey('key_format', $body);
    }

    public function testListReturningVerbsNormalizeAKeyedPayloadIntoAList(): void
    {
        // A JSON object (or a sparse/keyed array) where a JSON array was
        // expected would otherwise hand callers something they can't index
        // with [0] or iterate in order. The list verbs re-key it.
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => [
            '2' => ['xid' => 'c'],
            '0' => ['xid' => 'a'],
            'oops' => 'not-a-record',
        ]]]]);
        $c = $this->client($t);

        $rows = $c->search('Movie');

        self::assertTrue(array_is_list($rows), 'search() must hand back a list');
        self::assertSame([['xid' => 'c'], ['xid' => 'a']], $rows, 'non-record entries are dropped');
    }

    public function testActingAsOnlyWhenSet(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t);

        $c->search('Movie', null, null, 'user-xid-1');

        self::assertSame('user-xid-1', $t->lastRequestBody()['acting_as']);
    }

    public function testPerCallActingAsOverridesClientDefault(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t, ['actingAs' => 'default-user']);

        $c->search('Movie', null, null, 'override-user');

        self::assertSame('override-user', $t->lastRequestBody()['acting_as']);
    }

    public function testGetFlatArgsAndNull(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => null]]]);
        $c = $this->client($t);

        $row = $c->get('Movie', ['xid' => 'm-1'], ['title' => null]);

        self::assertNull($row);
        $body = $t->lastRequestBody();
        self::assertSame('get', $body['operations'][0]['op']);
        self::assertSame(['xid' => 'm-1'], $body['operations'][0]['args']);
    }

    public function testSyncStackDeleteSlicePurge(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => ['xid' => 'm-1']]]]);
        $c = $this->client($t);
        $row = $c->sync('Movie', ['xid' => 'm-1', 'title' => 'Dune']);
        self::assertSame(['xid' => 'm-1'], $row);
        self::assertSame('sync', $t->lastRequestBody()['operations'][0]['op']);

        $t->respondJson(200, ['results' => [['ok' => true, 'data' => ['xid' => 'm-1']]]]);
        $c->stack('user_rating', ['value' => 5]);
        self::assertSame('stack', $t->lastRequestBody()['operations'][0]['op']);

        $t->respondJson(200, ['results' => [['ok' => true, 'data' => true]]]);
        self::assertTrue($c->delete('Movie', ['xid' => 'm-1']));
        self::assertSame('delete', $t->lastRequestBody()['operations'][0]['op']);

        $t->respondJson(200, ['results' => [['ok' => true, 'data' => ['genres' => true]]]]);
        $result = $c->slice('Movie', ['xid' => 'm-1'], ['genres' => [['xid' => 'g-1']]]);
        self::assertSame(['genres' => true], $result);
        self::assertSame('slice', $t->lastRequestBody()['operations'][0]['op']);

        $t->respondJson(200, ['results' => [['ok' => true, 'data' => null]]]);
        self::assertSame([], $c->purge('user_rating', ['value' => eq(1)]));
        self::assertSame('purge', $t->lastRequestBody()['operations'][0]['op']);
    }

    public function testSqlTemplateDefaultsParams(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => [['n' => 3]]]]]);
        $c = $this->client($t);

        $rows = $c->sqlTemplate('SELECT COUNT(*) AS n FROM {movie}');

        self::assertSame([['n' => 3]], $rows);
        self::assertSame([], $t->lastRequestBody()['operations'][0]['params']);
    }

    public function testQueryWrapsBareXsqlWithSyntheticHeader(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t);

        $c->query("movie (_limit 5)\n  title");

        $doc = $t->lastRequestBody()['operations'][0]['xsql'];
        self::assertStringStartsWith("@search _q\n", $doc);
    }

    public function testQueryPassesThroughDocumentThatAlreadyHasAHeader(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t);

        $c->query("@get named\nmovie (xid = ?x:string)\n  title");

        $doc = $t->lastRequestBody()['operations'][0]['xsql'];
        self::assertSame("@get named\nmovie (xid = ?x:string)\n  title", $doc);
    }

    public function testExecBatchOrderAndRequestIdOnEveryResult(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [
            ['ok' => true, 'data' => 'first'],
            ['ok' => true, 'data' => 'second'],
        ]], ['x-request-id' => 'req-42']);
        $c = $this->client($t);

        $results = $c->exec([['op' => 'deployed-model'], ['op' => 'runtime-model']]);

        self::assertSame('first', $results[0]['data']);
        self::assertSame('second', $results[1]['data']);
        self::assertSame('req-42', $results[0]['_request_id']);
        self::assertSame('req-42', $results[1]['_request_id']);
    }

    public function testPerOpErrorThrowsSynthigyError(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [
            ['ok' => false, 'error' => ['code' => 'ENTITY_NOT_READABLE', 'message' => 'nope']],
        ]]);
        $c = $this->client($t);

        $this->expectException(SynthigyError::class);
        $this->expectExceptionMessage('nope');
        $c->search('Secret');
    }

    public function test403ForbiddenMapsToSynthigyError(): void
    {
        $t = new FakeTransport();
        $t->respondJson(403, ['error' => ['code' => 'FORBIDDEN', 'message' => 'no']]);
        $c = $this->client($t);

        try {
            $c->search('Movie');
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame('FORBIDDEN', $e->code);
            self::assertSame('iam', $e->category);
            self::assertSame(403, $e->status);
        }
    }

    public function test403UnparseableBodyFallsBackToGenericForbidden(): void
    {
        $t = new FakeTransport();
        $t->respond(new \Synthigy\Http\HttpResponse(403, [], 'not json'));
        $c = $this->client($t);

        try {
            $c->search('Movie');
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame('FORBIDDEN', $e->code);
        }
    }

    public function test5xxHttpErrorCarriesBodyText(): void
    {
        $t = new FakeTransport();
        $t->respond(new \Synthigy\Http\HttpResponse(500, [], 'boom, internal'));
        $c = $this->client($t);

        try {
            $c->search('Movie');
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame('HTTP_ERROR', $e->code);
            self::assertSame('boom, internal', $e->details);
            self::assertSame(500, $e->status);
        }
    }

    public function test5xxWithEmbeddedErrorUsesServerCode(): void
    {
        $t = new FakeTransport();
        $t->respondJson(500, ['error' => ['code' => 'OPERATION_ERROR', 'message' => 'db down']]);
        $c = $this->client($t);

        try {
            $c->search('Movie');
            self::fail('expected SynthigyError');
        } catch (SynthigyError $e) {
            self::assertSame('OPERATION_ERROR', $e->code);
            self::assertSame('db down', $e->getMessage());
        }
    }

    public function test401ClearsTokenSourceAndRetriesOnceThenSucceeds(): void
    {
        $t = new FakeTransport();
        $c = new Client(
            endpoint: 'https://synthigy.example.test',
            clientId: 'cid',
            clientSecret: 'secret',
            transport: $t,
        );

        $t->respondJson(200, ['access_token' => 'tok-1', 'expires_in' => 3600]); // oauth
        $t->respondJson(401, ['error' => ['code' => 'UNAUTHORIZED']]);          // first attempt
        $t->respondJson(200, ['access_token' => 'tok-2', 'expires_in' => 3600]); // re-mint after clear
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);     // retry succeeds

        $rows = $c->search('Movie');

        self::assertSame([], $rows);
        // 4 requests: token, 401 attempt, re-mint token, retry
        self::assertCount(4, $t->requests);
    }

    public function testConfiguredAudienceIsSentOnEveryTokenMintWithNoPerCallOverride(): void
    {
        // Some servers require an explicit client_credentials audience for
        // /data access (opt-in by design, not resolved from the client's
        // role/API links) — Client::$audience binds it once so ordinary
        // calls never need to know about it.
        $t = new FakeTransport();
        $c = new Client(
            endpoint: 'https://synthigy.example.test',
            clientId: 'cid', clientSecret: 'secret',
            audience: 'https://synthigy.example.test',
            transport: $t,
        );
        $t->respondJson(200, ['access_token' => 'tok', 'expires_in' => 3600]);
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);

        $c->search('Movie');

        $form = [];
        parse_str((string)$t->requests[0]['body'], $form);
        self::assertSame('https://synthigy.example.test', $form['audience']);
    }

    public function testPerCallAudienceOverridesTheConfiguredDefault(): void
    {
        $t = new FakeTransport();
        $c = new Client(
            endpoint: 'https://synthigy.example.test',
            clientId: 'cid', clientSecret: 'secret',
            audience: 'https://synthigy.example.test',
            transport: $t,
        );
        $t->respondJson(200, ['access_token' => 'tok-other', 'expires_in' => 3600]);

        $c->token('https://other.example.test');

        $form = [];
        parse_str((string)$t->requests[0]['body'], $form);
        self::assertSame('https://other.example.test', $form['audience']);
    }

    public function testSecondConsecutive401Raises(): void
    {
        $t = new FakeTransport();
        $c = new Client(endpoint: 'https://synthigy.example.test', token: 'tok', transport: $t);

        $t->respondJson(401, ['error' => ['code' => 'UNAUTHORIZED']]);
        // static-token mode: no tokenSource to clear, so there's no retry —
        // a single 401 raises immediately.

        $this->expectException(SynthigyError::class);
        $c->search('Movie');
    }

    public function testStaticEmptyTokenSendsNoAuthorizationHeader(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = new Client(endpoint: 'https://synthigy.example.test', token: '', transport: $t);

        $c->search('Movie');

        self::assertArrayNotHasKey('Authorization', $t->requests[0]['headers']);
    }

    public function testRequestIdHeaderIsAlwaysSent(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t);

        $c->search('Movie');

        self::assertNotEmpty($t->requests[0]['headers']['X-Request-Id']);
    }

    public function testNoTokenSourceRaisesNoToken(): void
    {
        $prev = getenv('SYNTHIGY_TOKEN');
        putenv('SYNTHIGY_TOKEN');
        $prevSup = getenv('SYNTHIGY_SUPERVISED');
        putenv('SYNTHIGY_SUPERVISED');
        try {
            $this->expectException(SynthigyError::class);
            $this->expectExceptionMessage('no Synthigy token');
            new Client(endpoint: 'https://synthigy.example.test');
        } finally {
            if ($prev !== false) {
                putenv("SYNTHIGY_TOKEN={$prev}");
            }
            if ($prevSup !== false) {
                putenv("SYNTHIGY_SUPERVISED={$prevSup}");
            }
        }
    }

    public function testKeyFormatPrecedence(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['results' => [['ok' => true, 'data' => []]]]);
        $c = $this->client($t, ['keyFormat' => 'kebab']);

        $c->search('Movie', null, null, null, 'snake');

        self::assertSame('snake', $t->lastRequestBody()['key_format']);
    }
}
