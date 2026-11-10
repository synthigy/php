<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\Http\HttpResponse;
use Synthigy\SynthigyError;
use Synthigy\Tests\Fixtures\FakeTransport;

final class FilesTest extends TestCase
{
    private const BASE = 'https://synthigy.example.test';

    private function client(FakeTransport $t): Client
    {
        return new Client(endpoint: self::BASE, token: 'tok', transport: $t);
    }

    /** @return array{method: string, url: string, body: ?string, headers: array<string,string>, timeout: ?float} */
    private static function last(FakeTransport $t): array
    {
        return $t->requests[count($t->requests) - 1];
    }

    private static function code(callable $fn): SynthigyError
    {
        try {
            $fn();
        } catch (SynthigyError $e) {
            return $e;
        }
        self::fail('expected a SynthigyError');
    }

    public function testPut(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['xid' => 'f1', 'name' => 'a b.txt', 'size' => 5, 'uploaded_by' => ['xid' => 'u1', 'name' => 'jane']]);
        $fi = $this->client($t)->files()->put('/docs/a b.txt', 'hello');
        self::assertSame(['f1', 'jane'], [$fi['xid'], $fi['uploaded_by']['name'] ?? null]);
        $r = self::last($t);
        self::assertSame(['PUT', self::BASE . '/files?path=%2Fdocs%2Fa+b.txt', 'hello'], [$r['method'], $r['url'], $r['body']]);
        self::assertSame('application/octet-stream', $r['headers']['Content-Type']);
        self::assertSame('Bearer tok', $r['headers']['Authorization']);

        $path = tempnam(sys_get_temp_dir(), 'syn');
        file_put_contents($path, "%PDF bytes\x00\xff");
        $fh = fopen($path, 'rb');
        self::assertNotFalse($fh);
        $t->respondJson(201, ['xid' => 'f2']);
        $this->client($t)->files()->put(['name' => 'scan.pdf'], $fh, 'application/pdf');
        fclose($fh);
        unlink($path);
        $r = self::last($t);
        self::assertSame(['POST', self::BASE . '/files?name=scan.pdf', "%PDF bytes\x00\xff"], [$r['method'], $r['url'], $r['body']]);
        self::assertSame('application/pdf', $r['headers']['Content-Type']);

        $t->respondJson(412, ['error' => ['code' => 'FILE_CHANGED', 'message' => 'changed']]);
        $files = $this->client($t)->files();
        $e = self::code(fn () => $files->put(['xid' => 'f1'], 'v2', ifMatch: ['hash' => 'abc']));
        self::assertSame(['FILE_CHANGED', 'conflict', 412], [$e->code, $e->category, $e->status]);
        self::assertSame([self::BASE . '/files/f1', '"abc"'], [self::last($t)['url'], self::last($t)['headers']['If-Match']]);
        self::assertSame('INVALID_PATH', self::code(fn () => $files->put([], 'x'))->code);
        self::assertSame('UNSUPPORTED_MEDIA_TYPE',
            self::code(fn () => $files->put('/x', 'a=b', 'application/x-www-form-urlencoded'))->code);
    }

    public function testInfoAndErrors(): void
    {
        $t = new FakeTransport();
        $t->respondJson(404, ['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'No such file']]);
        $t->respondJson(404, ['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'No such file']]);
        $t->respondJson(503, ['error' => ['code' => 'AUDIT_UNAVAILABLE', 'message' => 'down']]);
        $t->respondJson(400, ['error' => ['code' => 'INVALID_QUERY', 'message' => 'q']]);
        $t->respond(new HttpResponse(404, [], 'Not Found'));
        $t->respondJson(404, ['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'No such file']]);
        $files = $this->client($t)->files();

        self::assertNull($files->info('f2'));
        self::assertNull($files->info('/gone.txt'));
        self::assertSame(self::BASE . '/files/info?path=%2Fgone.txt', self::last($t)['url']);
        $e = self::code(fn () => $files->info('f3'));
        self::assertSame(['AUDIT_UNAVAILABLE', 'not_found', false], [$e->code, $e->category, $e->retryable]);
        $e = self::code(fn () => $files->search(' '));
        self::assertSame(['INVALID_QUERY', 'validation'], [$e->code, $e->category]);
        self::assertSame('FILES_UNAVAILABLE', self::code(fn () => $files->list())->code);
        self::assertSame('FILE_NOT_FOUND', self::code(fn () => $files->versions('/gone.txt'))->code);
    }

    public function testDownload(): void
    {
        $t = new FakeTransport();
        $t->respond(new HttpResponse(206, ['etag' => '"h1"'], '234'));
        $t->respond(new HttpResponse(200, [], '0123456789'));
        $t->respond(new HttpResponse(304, [], ''));
        $t->respondJson(200, ['xid' => 'f9']);
        $t->respond(new HttpResponse(200, [], 'old'));
        $files = $this->client($t)->files();

        self::assertSame('234', $files->download('f1', [2, 4]));
        self::assertSame('bytes=2-4', self::last($t)['headers']['Range']);
        $files->download('/a/r.txt', [null, 5]);
        self::assertSame(['bytes=-5', self::BASE . '/files?path=%2Fa%2Fr.txt'], [self::last($t)['headers']['Range'], self::last($t)['url']]);
        self::assertNull($files->download('f1', ifNoneMatch: 'h1'));
        self::assertSame('"h1"', self::last($t)['headers']['If-None-Match']);
        self::assertSame('old', $files->download('/a/r.txt', version: ['id' => 7]));
        self::assertSame(self::BASE . '/files/f9/versions/7', self::last($t)['url']);
    }

    public function testTimeoutSparesFileBytes(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['xid' => 'f1']);
        $t->respond(new HttpResponse(200, [], 'bytes'));
        $t->respondJson(200, ['xid' => 'f1']);
        $t->respond(new HttpResponse(200, [], 'PK'));
        $files = (new Client(endpoint: self::BASE, token: 'tok', timeoutSeconds: 5.0, transport: $t))->files();

        $files->info('f1');
        $files->download('f1');
        $files->put('f1', 'v2');
        $files->zip(['f1']);
        self::assertSame([5.0, null, null, null], array_column($t->requests, 'timeout'));
    }

    public function testZip(): void
    {
        $t = new FakeTransport();
        $t->respond(new HttpResponse(200, ['content-type' => 'application/zip'], 'PK'));
        $t->respondJson(413, ['error' => ['code' => 'ZIP_TOO_LARGE', 'message' => 'too big']]);
        $files = $this->client($t)->files();

        self::assertSame('PK', $files->zip(['f1', '/a/r.txt', ['xid' => 'f9']], ['/site/img/', ['xid' => 'd1', 'path' => '/b/']]));
        $req = self::last($t);
        self::assertSame(['POST', self::BASE . '/files/zip', 'application/json'], [$req['method'], $req['url'], $req['headers']['Content-Type']]);
        self::assertSame(['files' => ['f1', '/a/r.txt', 'f9'], 'folders' => ['/site/img/', '/b/']], json_decode((string)$req['body'], true));
        self::assertSame('ZIP_TOO_LARGE', self::code(fn () => $files->zip(folders: ['/']))->code);
    }

    public function testLinkLinksUnlink(): void
    {
        $t = new FakeTransport();
        $made = ['xid' => 'l1', 'file' => 'f9', 'url' => self::BASE . '/files/link/t', 'downloads' => 0, 'max_downloads' => 2, 'inline' => true];
        $t->respondJson(200, ['xid' => 'f9']);
        $t->respondJson(201, $made);
        $t->respondJson(200, ['file' => 'f9', 'links' => [$made]]);
        $t->respond(new HttpResponse(204, [], ''));
        $t->respond(new HttpResponse(204, [], ''));
        $files = $this->client($t)->files();

        self::assertSame($made['url'], $files->link('/a.txt', expires: 600, maxDownloads: 2, inline: true)['url'] ?? null);
        self::assertSame('l1', $files->links('f9')[0]['xid']);
        $files->unlink($made);
        $files->unlink('l1');
        self::assertSame([
            ['GET', self::BASE . '/files/info?path=%2Fa.txt'],
            ['POST', self::BASE . '/files/f9/links'],
            ['GET', self::BASE . '/files/f9/links'],
            ['DELETE', self::BASE . '/files/links/l1'],
            ['DELETE', self::BASE . '/files/links/l1'],
        ], array_map(static fn ($r) => [$r['method'], $r['url']], $t->requests));
        self::assertSame(['expires' => 600, 'max_downloads' => 2, 'inline' => true], json_decode((string)$t->requests[1]['body'], true));
    }

    public function testLinkRefusalsAndCanLink(): void
    {
        $t = new FakeTransport();
        $t->respondJson(403, ['error' => ['code' => 'LINKS_DISABLED', 'message' => 'off']]);
        $t->respondJson(400, ['error' => ['code' => 'INVALID_EXPIRES', 'message' => 'bad']]);
        $t->respondJson(404, ['error' => ['code' => 'LINK_NOT_FOUND', 'message' => 'gone']]);
        $t->respondJson(200, ['xid' => 'd1', 'path' => '/s/']);
        $files = $this->client($t)->files();

        $e = self::code(fn () => $files->link('f9'));
        self::assertSame(['LINKS_DISABLED', 'iam'], [$e->code, $e->category]);
        self::assertSame('{}', $t->requests[0]['body']);
        $e = self::code(fn () => $files->link('f9', expires: 0));
        self::assertSame(['INVALID_EXPIRES', 'validation'], [$e->code, $e->category]);
        $e = self::code(fn () => $files->unlink('l9'));
        self::assertSame(['LINK_NOT_FOUND', 'not_found'], [$e->code, $e->category]);
        $files->folder('/s/', ['can_link' => true, 'cache' => 60]);
        self::assertSame(['can_link' => true, 'cache' => 60], json_decode((string)self::last($t)['body'], true));
    }

    public function testKeyFormatReachesFiles(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['xid' => 'f9']);
        $t->respondJson(200, ['xid' => 'f9']);
        $t->respondJson(200, ['file' => 'f9', 'links' => []]);
        $t->respondJson(200, ['xid' => 'f9']);
        $camel = new Client(endpoint: self::BASE, token: 'tok', keyFormat: 'camel', transport: $t);
        $snake = new Client(endpoint: self::BASE, token: 'tok', keyFormat: 'snake', transport: $t);

        $camel->files()->info('f9');
        $snake->files()->info('f9');
        $camel->files()->links('f9');
        $camel->files()->info('/a b.txt');
        self::assertSame([
            self::BASE . '/files/f9/info?key_format=camel',
            self::BASE . '/files/f9/info',
            self::BASE . '/files/f9/links?key_format=camel',
            self::BASE . '/files/info?path=%2Fa+b.txt&key_format=camel',
        ], array_column($t->requests, 'url'));
        self::assertSame(self::BASE . '/files/f9', $camel->files()->url('f9'));
    }

    public function testListSearchMove(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['files' => [['xid' => 'f1']]]);
        $t->respondJson(200, ['files' => []]);
        $t->respondJson(200, ['files' => [['xid' => 'f1']]]);
        $t->respondJson(200, ['parent' => 'd0', 'folders' => [['xid' => 'd1', 'name' => 'Sub Two'], ['xid' => 'd2', 'name' => 'other']]]);
        $t->respondJson(200, ['truncated' => true, 'results' => [['type' => 'folder', 'xid' => 'd1', 'path' => '/a/Sub Two/'], ['type' => 'file', 'xid' => 'f1', 'path' => null]]]);
        $t->respondJson(200, ['xid' => 'f1', 'folder' => ['xid' => 'd1', 'path' => '/b/']]);
        $t->respondJson(200, ['xid' => 'f1']);
        $t->respondJson(200, ['xid' => 'd3', 'path' => '/b/new/']);
        $t->respondJson(200, ['xid' => 'd3', 'path' => '/top/']);
        $files = $this->client($t)->files();

        self::assertSame([], $files->list(limit: 5, offset: 10)['folders']);
        self::assertSame(self::BASE . '/files?limit=5&offset=10', self::last($t)['url']);
        $files->list(null, 'inv');
        self::assertSame(self::BASE . '/files?q=inv&folderless=true', self::last($t)['url']);
        $ls = $files->list('/a/', 'sub two');
        self::assertSame(['d1'], array_column($ls['folders'], 'xid'));
        self::assertSame(self::BASE . '/files?q=sub+two&folder=%2Fa%2F', $t->requests[2]['url']);
        self::assertSame(self::BASE . '/files/folders?parent=%2Fa%2F', self::last($t)['url']);

        $hits = $files->search('sub x', ['xid' => 'd0', 'path' => '/a/'], 2);
        self::assertSame(self::BASE . '/files/search?q=sub+x&folder=%2Fa%2F&limit=2', self::last($t)['url']);
        self::assertSame([['folder', 'file'], true], [array_column($hits['results'], 'type'), $hits['truncated']]);

        self::assertSame('/b/', $files->move('/a/x.png', '/b/n.png')['folder']['path'] ?? null);
        self::assertSame(['PATCH', self::BASE . '/files?path=%2Fa%2Fx.png', '{"folder":"\/b\/","name":"n.png"}'],
            [self::last($t)['method'], self::last($t)['url'], self::last($t)['body']]);
        $files->move(['xid' => 'f1'], '/b/');
        self::assertSame([self::BASE . '/files/f1', '{"folder":"\/b\/"}'], [self::last($t)['url'], self::last($t)['body']]);
        self::assertSame('/b/new/', $files->move('/a/old/', '/b/new/')['path'] ?? null);
        self::assertSame([self::BASE . '/files/folders?path=%2Fa%2Fold%2F', '{"name":"new","parent":"\/b\/"}'],
            [self::last($t)['url'], self::last($t)['body']]);
        $files->move(['xid' => 'd9', 'path' => '/a/old/'], '/top/');
        self::assertSame('{"name":"top","parent":"\/"}', self::last($t)['body']);
        self::assertSame('INVALID_PATH', self::code(fn () => $files->move('f1', 'n.png'))->code);
    }

    public function testFoldersAndSharing(): void
    {
        $t = new FakeTransport();
        $t->respondJson(200, ['xid' => 'd1', 'public' => true, 'cache' => null, 'settings' => ['public' => true, 'cache' => null]]);
        $t->respondJson(200, ['xid' => 'd2']);
        $acl = ['read' => ['users' => [['xid' => 'u2', 'name' => 'bob']], 'groups' => []], 'write' => ['users' => [], 'groups' => []], 'delete' => ['users' => [], 'groups' => []]];
        $t->respondJson(200, $acl);
        $t->respondJson(200, $acl);
        $t->respondJson(200, $acl);
        $t->respondJson(200, ['xid' => 'f1', 'versions' => [['id' => 7, 'at' => '2026-10-29T17:40:12.532Z', 'by' => 'u1', 'current' => true]]]);
        $t->respondJson(200, ['xid' => 'f1']);
        $t->respond(new HttpResponse(204, [], ''));
        $t->respond(new HttpResponse(204, [], ''));
        $t->respond(new HttpResponse(204, [], ''));
        $files = $this->client($t)->files();

        $d = $files->folder('/a b/', ['public' => true, 'cache' => null, 'other' => 1]);
        self::assertSame([true, ['public' => true, 'cache' => null]], [$d['public'], $d['settings']]);
        self::assertSame(['PUT', self::BASE . '/files/folders?path=%2Fa+b%2F', '{"public":true,"cache":null}'],
            [self::last($t)['method'], self::last($t)['url'], self::last($t)['body']]);
        $files->folder('/x/');
        self::assertSame('{}', self::last($t)['body']);
        $files->share('/a b/', 'read', [['xid' => 'u2']]);
        self::assertSame([self::BASE . '/files/folders/share?path=%2Fa+b%2F', '{"access":"read","users":["u2"],"groups":[]}'],
            [self::last($t)['url'], self::last($t)['body']]);
        $files->unshare('d1', 'read', groups: ['g1']);
        self::assertSame(self::BASE . '/files/folders/d1/unshare', self::last($t)['url']);
        self::assertSame('bob', $files->access('/a b/')['read']['users'][0]['name']);
        self::assertSame(self::BASE . '/files/folders/access?path=%2Fa+b%2F', self::last($t)['url']);
        $vs = $files->versions('f1');
        self::assertSame([7, true], [$vs[0]['id'], $vs[0]['current']]);
        $files->restore('f1', $vs[0]);
        self::assertSame(['POST', self::BASE . '/files/f1/versions/7/restore'], [self::last($t)['method'], self::last($t)['url']]);
        $files->delete('/a b/r.txt');
        self::assertSame(['DELETE', self::BASE . '/files?path=%2Fa+b%2Fr.txt'], [self::last($t)['method'], self::last($t)['url']]);
        $files->delete('/a b/');
        self::assertSame(self::BASE . '/files/folders?path=%2Fa+b%2F', self::last($t)['url']);
        $files->delete(['xid' => 'f1']);
        self::assertSame(self::BASE . '/files/f1', self::last($t)['url']);
    }

    public function testUrlAndServe(): void
    {
        $t = new FakeTransport();
        $t->respond(new HttpResponse(206, ['etag' => '"h1"', 'content-range' => 'bytes 0-1/5', 'x-internal' => 'no'], 'he'));
        $t->respondJson(404, ['error' => ['code' => 'FILE_NOT_FOUND', 'message' => 'No such file']]);
        $files = $this->client($t)->files();

        self::assertSame(self::BASE . '/files/path/site/a%20b%281%29%2A.txt', $files->url('/site/a b(1)*.txt'));
        self::assertSame(self::BASE . '/files/f1?disposition=inline', $files->url(['xid' => 'f1'], true));
        self::assertSame([], $t->requests, 'url makes no request');
        self::assertSame('INVALID_PATH', self::code(fn () => $files->url('/a/'))->code);

        $out = $files->serve('/note.txt', ['range' => 'bytes=0-1', 'Cookie' => 'x'], inline: true);
        self::assertSame([206, 'he', '"h1"', null], [$out->status, $out->body, $out->header('etag'), $out->header('x-internal')]);
        $r = self::last($t);
        self::assertSame(self::BASE . '/files?path=%2Fnote.txt&disposition=inline', $r['url']);
        self::assertSame(['bytes=0-1', null], [$r['headers']['Range'] ?? null, $r['headers']['Cookie'] ?? null]);
        $out = $files->serve('/gone.txt');
        self::assertSame(404, $out->status);
        self::assertStringContainsString('FILE_NOT_FOUND', $out->body);

        $t->respond(new HttpResponse(401, [], ''));
        $t->respond(new HttpResponse(401, [], ''));
        self::assertSame('UNAUTHORIZED', self::code(fn () => $files->serve('/note.txt'))->code);
    }
}
