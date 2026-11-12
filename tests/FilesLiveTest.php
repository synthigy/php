<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Synthigy\Client;
use Synthigy\SynthigyError;

/**
 * Live files suite: three clients (File Admin, File User, File Reader) against a real server.
 *
 * Env: SYNTHIGY_FILES_TEST_ENDPOINT and SYNTHIGY_FILES_TEST_{ADMIN,USER,READER,BFF}_{ID,SECRET} (BFF: trusted, File Admin).
 *
 * @phpstan-import-type FileInfo from \Synthigy\Files
 */
#[Group('live')]
final class FilesLiveTest extends TestCase
{
    private Client $admin;
    private Client $user;
    private Client $reader;
    private Client $bff;
    private string $run;
    private string $top;

    /** @var list<string> */
    private array $files = [];

    /** @var list<string> */
    private array $folders = [];

    protected function setUp(): void
    {
        $endpoint = getenv('SYNTHIGY_FILES_TEST_ENDPOINT') ?: null;
        if ($endpoint === null) {
            self::markTestSkipped('live files suite needs SYNTHIGY_FILES_TEST_ENDPOINT and SYNTHIGY_FILES_TEST_{ADMIN,USER,READER,BFF}_{ID,SECRET}');
        }
        $this->admin = self::client($endpoint, 'ADMIN');
        $this->user = self::client($endpoint, 'USER');
        $this->reader = self::client($endpoint, 'READER');
        $this->bff = self::client($endpoint, 'BFF');
        $this->run = 'php-' . bin2hex(random_bytes(4));
        $this->top = "/F-{$this->run}/";
    }

    private static function client(string $endpoint, string $who): Client
    {
        $id = getenv("SYNTHIGY_FILES_TEST_{$who}_ID") ?: null;
        $secret = getenv("SYNTHIGY_FILES_TEST_{$who}_SECRET") ?: null;
        if ($id === null || $secret === null) {
            self::markTestSkipped("live files suite needs SYNTHIGY_FILES_TEST_{$who}_ID and _SECRET");
        }
        return new Client(endpoint: $endpoint, clientId: $id, clientSecret: $secret, tokenStore: false);
    }

    protected function tearDown(): void
    {
        foreach ([...array_reverse($this->files), ...array_reverse($this->folders)] as $ref) {
            try {
                $this->admin->files()->delete($ref);
            } catch (\Throwable) {
            }
        }
    }

    private static function err(callable $fn): SynthigyError
    {
        try {
            $fn();
        } catch (SynthigyError $e) {
            return $e;
        }
        self::fail('expected a SynthigyError');
    }

    private static function expectErr(string $code, string $category, callable $fn): void
    {
        $e = self::err($fn);
        self::assertSame([$code, $category], [$e->code, $e->category], $e->getMessage());
    }

    /**
     * @param list<array{xid: string, name: ?string}> $grantees
     * @return list<string>
     */
    private static function xids(array $grantees): array
    {
        return array_map(static fn (array $g): string => $g['xid'], $grantees);
    }

    /** @return array{0: int, 1: string, 2: array<string,string>} */
    private static function fetch(string $url): array
    {
        $body = file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        preg_match('/ (\d{3})/', $http_response_header[0] ?? '', $m);
        $headers = [];
        foreach ($http_response_header as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return [(int)($m[1] ?? 0), (string)$body, $headers];
    }

    /**
     * @param string|array{name: string} $target
     * @return FileInfo
     */
    private function put(Client $c, string|array $target, string $data, ?string $contentType = null): array
    {
        $f = $c->files()->put($target, $data, $contentType);
        if (!in_array($f['xid'], $this->files, true)) {
            $this->files[] = $f['xid'];
        }
        return $f;
    }

    public function testFilesScenario(): void
    {
        $admin = $this->admin->files();
        $user = $this->user->files();
        $reader = $this->reader->files();
        $top = $this->top;
        $run = $this->run;
        $bin = "\x00\x01\xff\x7f\x80\x00\xfe\xff\x10\x20";
        $next = "\xff\x00\xaa\x55\x00";
        $badJson = "\xff\xfe{}";

        // 1. admin makes the top folder and shares it
        self::assertSame($top, $admin->folder($top)['path']);
        $this->folders[] = $top;
        $userXid = $this->user->whoami()['xid'];
        $readerXid = $this->reader->whoami()['xid'];
        foreach (['write', 'read', 'delete'] as $access) {
            $admin->share($top, $access, [$userXid]);
        }
        $admin->share($top, 'read', [$readerXid]);
        $acl = $admin->access($top);
        foreach (['write', 'read', 'delete'] as $access) {
            self::assertContains($userXid, self::xids($acl[$access]['users']), $access);
        }
        self::assertContains($readerXid, self::xids($acl['read']['users']));
        self::assertNotContains($readerXid, self::xids($acl['write']['users']));

        // 2. user puts, replaces and versions
        $binPath = "{$top}bin-{$run}.bin";
        $f = $this->put($this->user, $binPath, $bin);
        self::assertSame(strlen($bin), $f['size']);
        self::assertSame($bin, $user->download($binPath));
        $f2 = $user->put($binPath, $next);
        self::assertSame($f['xid'], $f2['xid']);
        self::assertNotSame($f['hash'], $f2['hash']);
        self::expectErr('FILE_CHANGED', 'conflict', fn () => $user->put($f, $bin, ifMatch: str_repeat('0', 64)));
        self::expectErr('FOLDER_NOT_FOUND', 'not_found', fn () => $user->put("{$top}nope/x.bin", $bin));
        self::expectErr('INVALID_NAME', 'validation', fn () => $user->put("{$top}a\\b", $bin));

        $versions = [];
        for ($until = microtime(true) + 30; microtime(true) < $until; usleep(500_000)) {
            $versions = $user->versions($binPath);
            if (count($versions) >= 2) {
                break;
            }
        }
        self::assertCount(2, $versions);
        self::assertTrue($versions[0]['current']);
        self::assertSame($f['hash'], $versions[1]['hash']);
        self::assertSame($bin, $user->download($f, version: $versions[1]));
        self::assertSame($f['hash'], $user->restore($binPath, $versions[1])['hash']);
        self::assertSame($bin, $user->download($f));
        self::assertSame(substr($bin, 2, 3), $user->download($binPath, [2, 4]));
        self::assertNull($user->download($f, ifNoneMatch: (string)$f['hash']));

        $json = $this->put($this->user, "{$top}bad-{$run}.json", $badJson, 'application/json');
        self::assertSame($badJson, $user->download($json));
        self::assertSame($f['xid'], $user->info($binPath)['xid'] ?? null);
        self::assertSame($top, $user->info($f['xid'])['folder']['path'] ?? null);
        self::assertNull($user->info("{$top}missing.bin"));
        $ls = $user->list($top);
        self::assertEqualsCanonicalizing([$f['xid'], $json['xid']], array_column($ls['files'], 'xid'));
        self::assertSame([], $ls['folders']);
        self::assertSame([$f['xid']], array_column($user->list(q: "bin-{$run}")['files'], 'xid'));

        $loose = $this->put($this->user, ['name' => "loose-{$run}.txt"], 'loose');
        self::assertNull($loose['folder'] ?? null);
        $twin = $user->put(['name' => "loose-{$run}.txt"], 'twin');
        self::assertNotSame($loose['xid'], $twin['xid']);
        $user->delete($twin);
        self::assertSame([$loose['xid']], array_column($user->list(null, "loose-{$run}")['files'], 'xid'));

        // 3. user folders and moves
        $user->folder("{$top}sub/");
        $this->folders[] = "{$top}sub/";
        self::assertSame("{$top}sub2/", $user->move("{$top}sub/", "{$top}sub2/")['path'] ?? null);
        $this->folders[] = "{$top}sub2/";
        self::assertSame(["{$top}sub2/"], array_column($user->list($top)['folders'], 'path'));
        self::assertSame("{$top}sub2/", $user->move($f, "{$top}sub2/")['folder']['path'] ?? null);
        self::assertSame($top, $user->move("{$top}sub2/bin-{$run}.bin", $top)['folder']['path'] ?? null);
        self::expectErr('FORBIDDEN', 'iam', fn () => $user->move("{$top}sub2/", "/sub2-{$run}/"));
        self::expectErr('FOLDER_NOT_EMPTY', 'conflict', fn () => $user->delete($top));

        // 3b. search and list q
        $hit = $user->search("F-{$run} sub2");
        self::assertSame([['folder', "{$top}sub2/"]], array_map(static fn ($r) => [$r['type'], $r['path']], $hit['results']));
        self::assertFalse($hit['truncated']);
        $hit = $user->search("{$run} bin");
        self::assertSame([['file', $f['xid'], $binPath]], array_map(static fn ($r) => [$r['type'], $r['xid'], $r['path']], $hit['results']));
        self::assertSame([], $user->search(".bin F-{$run}")['results']);
        $all = $user->search("F-{$run}")['results'];
        self::assertGreaterThanOrEqual(4, count($all));
        self::assertSame($top, $all[0]['path']);
        $paged = [];
        for ($offset = 0, $more = true; $more; $offset += 2) {
            $page = $user->search("F-{$run}", limit: 2, offset: $offset);
            $paged = [...$paged, ...$page['results']];
            $more = $page['truncated'];
        }
        self::assertSame(array_column($all, 'xid'), array_column($paged, 'xid'));
        self::assertSame(["{$top}sub2/"], array_column($user->search('sub2', $top)['results'], 'path'));
        self::assertSame([[$loose['xid'], null]], array_map(static fn ($r) => [$r['xid'], $r['path']], $user->search("loose-{$run}")['results']));
        self::assertSame([], $reader->search("loose-{$run}")['results']);
        self::assertSame([], $user->list($top, '%')['files']);
        self::assertSame(["{$top}sub2/"], array_column($user->list($top, 'sub')['folders'], 'path'));
        self::expectErr('INVALID_QUERY', 'validation', fn () => $user->search('  '));

        // 4. reader sees and downloads, may not write
        $seen = array_column($reader->list('/')['folders'], null, 'path');
        self::assertArrayHasKey($top, $seen);
        self::assertFalse($seen[$top]['writable']);
        self::assertSame($bin, $reader->download($f['xid']));
        self::assertNull($reader->info($loose));
        self::expectErr('FORBIDDEN', 'iam', fn () => $reader->put("{$top}r-{$run}.bin", $bin));
        self::expectErr('FORBIDDEN', 'iam', fn () => $reader->put($f, $next));
        self::expectErr('FORBIDDEN', 'iam', fn () => $reader->folder("{$top}r-{$run}/"));

        // 4b. a trusted client acting as the user and the reader
        $bff = $this->bff->files();
        self::assertSame($loose['xid'], $bff->info($loose)['xid'] ?? null);
        self::assertNull($bff->info($loose, actingAs: $readerXid));
        self::assertSame($bin, $bff->download($f['xid'], actingAs: $readerXid));
        self::expectErr('FORBIDDEN', 'iam', fn () => $bff->put("{$top}bff-r-{$run}.bin", $bin, actingAs: $readerXid));
        $own = $bff->put("{$top}bff-u-{$run}.bin", $bin, actingAs: $userXid);
        self::assertSame($userXid, $own['uploaded_by']['xid'] ?? null);
        $bff->delete($own, actingAs: $userXid);
        self::assertNull($bff->info($own));
        self::assertSame('NOT_TRUSTED', self::err(fn () => $admin->info($f, actingAs: $readerXid))->code);

        // 5. links for browsers
        $notePath = "{$top}note-{$run}.txt";
        $note = $this->put($this->user, $notePath, 'hello');
        self::assertSame(401, self::fetch($user->url($note))[0]);
        self::expectErr('ATTRIBUTE_FORBIDDEN', 'iam', fn () => $user->folder($top, ['public' => true]));
        $user->folder($top, ['cache' => 120]);
        $shown = $admin->folder($top, ['public' => true]);
        self::assertSame([true, 120, ['public' => true, 'cache' => 120, 'can_link' => null]], [$shown['public'], $shown['cache'], $shown['settings']]);
        self::assertTrue($user->info($note)['public'] ?? null);
        foreach ([$user->url($note), $user->url($notePath)] as $url) {
            [$status, $body, $headers] = self::fetch($url);
            self::assertSame([200, 'hello', 'public, max-age=120', '*'],
                [$status, $body, $headers['cache-control'] ?? null, $headers['access-control-allow-origin'] ?? null], $url);
        }
        self::assertStringStartsWith('inline', self::fetch($user->url($note, true))[2]['content-disposition'] ?? '');
        $inner = $admin->folder("{$top}inner/");
        $this->folders[] = "{$top}inner/";
        self::assertSame([true, null], [$inner['public'], $inner['settings']['public']]);
        $secret = $this->put($this->user, "{$top}inner/secret-{$run}.txt", 'secret');
        self::assertSame(200, self::fetch($user->url($secret))[0]);
        $admin->folder("{$top}inner/", ['public' => false]);
        self::assertSame(401, self::fetch($user->url($secret))[0]);
        $admin->folder("{$top}inner/", ['public' => null]);
        self::assertSame(200, self::fetch($user->url($secret))[0]);
        $user->delete($secret);
        $admin->delete("{$top}inner/");
        $out = $user->serve($notePath, ['Range' => 'bytes=0-1']);
        self::assertSame([206, 'he'], [$out->status, $out->body]);
        self::assertSame(404, $user->serve("{$top}gone-{$run}.txt")->status);

        // 5b. download links
        $shut = $this->put($this->user, "{$top}link-{$run}.txt", 'linked');
        $admin->folder($top, ['public' => false]);
        self::expectErr('LINKS_DISABLED', 'iam', fn () => $user->link($shut));
        self::expectErr('ATTRIBUTE_FORBIDDEN', 'iam', fn () => $user->folder($top, ['can_link' => true]));
        self::assertTrue($admin->folder($top, ['can_link' => true])['can_link']);
        self::expectErr('FORBIDDEN', 'iam', fn () => $reader->link($shut));
        self::expectErr('INVALID_EXPIRES', 'validation', fn () => $user->link($shut, expires: 0));

        $link = $user->link($shut, expires: 600);
        self::assertSame([$shut['xid'], $userXid, 0], [$link['file'], $link['created_by'], $link['downloads']]);
        [$status, $body, $headers] = self::fetch($link['url'] ?? '');
        self::assertSame([200, 'linked', 'no-referrer'], [$status, $body, $headers['referrer-policy'] ?? null]);

        $once = $user->link($shut, maxDownloads: 1, inline: true);
        $first = file_get_contents($once['url'] ?? '', false, stream_context_create(['http' => ['ignore_errors' => true, 'header' => 'Range: bytes=1-2']]));
        self::assertSame([1, 'linked'], [preg_match('/ 200 /', $http_response_header[0] ?? ''), $first], 'a limited link ignores Range');
        self::assertNotEmpty(preg_grep('/^content-disposition:\s*inline/i', $http_response_header));
        self::assertSame(404, self::fetch($once['url'] ?? '')[0], 'used up');

        $camel = new Client(endpoint: (string)getenv('SYNTHIGY_FILES_TEST_ENDPOINT'), clientId: (string)getenv('SYNTHIGY_FILES_TEST_USER_ID'),
            clientSecret: (string)getenv('SYNTHIGY_FILES_TEST_USER_SECRET'), keyFormat: 'camel', tokenStore: false);
        $seen = array_values(array_filter($camel->files()->links($shut), static fn ($l) => $l['xid'] === $link['xid']))[0];
        self::assertSame([$userXid, null, false], [$seen['createdBy'] ?? 'missing', $seen['maxDownloads'] ?? null, array_key_exists('created_by', $seen)], 'keyFormat camel');
        self::assertSame($user->info($shut)['content_type'] ?? 'x', $camel->files()->info($shut)['contentType'] ?? 'y');
        $mine = array_column($user->links($shut), null, 'xid');
        self::assertArrayHasKey($link['xid'], $mine);
        self::assertSame([], array_filter($mine, static fn ($l) => array_key_exists('url', $l)), 'the url is shown once');
        self::assertSame(1, $mine[$link['xid']]['downloads']);
        $admins = $admin->link($shut);
        self::assertArrayNotHasKey($admins['xid'], array_column($user->links($shut), null, 'xid'), 'File User sees its own');
        self::assertArrayHasKey($link['xid'], array_column($admin->links($shut), null, 'xid'), 'File Admin sees all');
        self::expectErr('LINK_NOT_FOUND', 'not_found', fn () => $user->unlink($admins['xid']));

        $admin->folder($top, ['can_link' => false]);
        self::assertSame(404, self::fetch($link['url'] ?? '')[0], 'switched off: existing links stop');
        $admin->folder($top, ['can_link' => true]);
        self::assertSame(200, self::fetch($link['url'] ?? '')[0]);
        $user->unlink($link);
        self::assertSame(404, self::fetch($link['url'] ?? '')[0], 'unlinked: gone at once');
        $user->delete($shut);
        self::assertSame(404, self::fetch($admins['url'] ?? '')[0], 'file deleted: its links die');
        $admin->folder($top, ['public' => true, 'can_link' => null]);

        // 6. admin moves the subfolder to ROOT and deletes it
        self::assertSame("/sub2-{$run}/", $admin->move("{$top}sub2/", "/sub2-{$run}/")['path'] ?? null);
        $admin->delete("/sub2-{$run}/");

        // 7. cleanup
        foreach ($this->files as $xid) {
            try {
                $user->delete($xid);
            } catch (SynthigyError) {
            }
            self::assertNull($user->info($xid));
        }
        $this->files = [];
        $admin->delete($top);
        self::assertSame([], $admin->list('/', "F-{$run}")['folders']);
        $this->folders = [];
    }
}
