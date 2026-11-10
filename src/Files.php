<?php

declare(strict_types=1);

namespace Synthigy;

use Synthigy\Http\HttpResponse;

/**
 * Files: the bytes behind /files, each also a File record on /data.
 * Obtain it with $client->files(). A file is a path (/a/b/report.pdf), an
 * xid or the file itself; a folder a path ending in / (/ is ROOT), an xid or
 * the folder itself.
 *
 * @phpstan-type FileInfo array{xid: string, name: ?string, size: ?int, hash: ?string, status: ?string, content_type: ?string, uploaded_at: ?string, uploaded_by: ?array{xid: string, name: ?string}, folder: ?array{xid: string, name?: string, path?: string}, public: bool}
 * @phpstan-type FolderInfo array{xid: string, name: string, path: string, parent: ?string, writable: bool, public: bool, cache: ?int, can_link: bool, settings: array{public: ?bool, cache: ?int, can_link: ?bool}}
 * @phpstan-type FileLink array{xid: string, file: string, created_by: ?string, expires_at: string, max_downloads: ?int, downloads: int, inline: bool, url?: string}
 */
final class Files
{
    private const SERVED = ['content-type', 'content-length', 'content-range', 'accept-ranges', 'etag',
        'content-disposition', 'cache-control', 'x-content-type-options', 'content-security-policy'];

    /** @param \Closure(string, string, ?string, array<string,string>, bool): HttpResponse $request */
    public function __construct(
        private readonly string $endpoint,
        private readonly \Closure $request,
        private readonly ?string $keyFormat = null,
    ) {
    }

    /** @param string|array{xid?: string, path?: string} $x */
    private static function xid(string|array $x): string
    {
        return is_array($x) ? (string)($x['xid'] ?? '') : $x;
    }

    /** @phpstan-assert-if-true =string $x */
    private static function isPath(mixed $x): bool
    {
        return is_string($x) && str_starts_with($x, '/');
    }

    private static function isFolder(mixed $x): bool
    {
        $p = is_array($x) ? ($x['path'] ?? null) : $x;
        return self::isPath($p) && str_ends_with($p, '/');
    }

    /** @param string|array{xid?: string, path?: string} $x */
    private static function folderRef(string|array $x): string
    {
        return is_array($x) ? (string)($x['path'] ?? $x['xid'] ?? '') : $x;
    }

    private static function seg(string|int $x): string
    {
        return '/' . rawurlencode((string)$x);
    }

    /** @param array<string,string|int|null> $query */
    private function at(string $path, array $query = []): string
    {
        if ($this->keyFormat !== null && $this->keyFormat !== 'snake') {
            $query['key_format'] = $this->keyFormat;
        }
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        return $this->endpoint . '/files' . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    /**
     * One file's endpoint: /<xid>, or ?path= for a path.
     *
     * @param string|array{xid?: string, path?: string, name?: string} $file
     * @return array{0: string, 1: array<string,string>}
     */
    private static function fileAt(string|array $file, string $tail = ''): array
    {
        return self::isPath($file) ? [$tail, ['path' => $file]] : [self::seg(self::xid($file)) . $tail, []];
    }

    /**
     * One folder's endpoint: /folders/<xid>, or /folders?path= for a path.
     *
     * @param string|array{xid?: string, path?: string} $folder
     * @return array{0: string, 1: array<string,string>}
     */
    private static function folderAt(string|array $folder, string $tail = ''): array
    {
        $ref = self::folderRef($folder);
        return self::isPath($ref) ? ['/folders' . $tail, ['path' => $ref]] : ['/folders' . self::seg($ref) . $tail, []];
    }

    /** @param string|array{xid?: string} $file */
    private function xidAt(string|array $file): string
    {
        if (!self::isPath($file)) {
            return self::xid($file);
        }
        $f = $this->info($file);
        if ($f === null) {
            throw new SynthigyError('No such file: ' . $file, 'FILE_NOT_FOUND', status: 404);
        }
        return $f['xid'];
    }

    /**
     * One /files request; a refusal becomes a SynthigyError.
     *
     * @param array<string,string> $headers
     * @internal
     */
    public function send(string $method, string $url, ?string $body = null, array $headers = [], bool $fileBytes = false): HttpResponse
    {
        $resp = ($this->request)($method, $url, $body, $headers, $fileBytes);
        if (($resp->status >= 200 && $resp->status < 300) || $resp->status === 304) {
            return $resp;
        }
        $requestId = $resp->header('x-request-id');
        $decoded = json_decode($resp->body, true);
        $err = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : null;
        if (($err !== null && ($err['code'] ?? null) === 'NOT_FOUND') || ($err === null && $resp->status === 404)) {
            throw new SynthigyError(
                'Files are not available on this server — is the files module running?',
                'FILES_UNAVAILABLE',
                status: $resp->status,
                requestId: $requestId,
            );
        }
        if ($err !== null) {
            throw SynthigyError::fromServer($err, $resp->status, $requestId);
        }
        throw new SynthigyError('Files request failed (' . $resp->status . ')', 'HTTP_ERROR', $resp->body, status: $resp->status, requestId: $requestId);
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,string|int|null> $query
     * @return array<string,mixed>
     * @internal
     */
    public function json(string $method, string $path, ?array $payload = null, array $query = []): array
    {
        $body = null;
        $headers = [];
        if ($payload !== null) {
            $body = json_encode($payload === [] ? new \stdClass() : $payload);
            if ($body === false) {
                throw new SynthigyError('failed to encode request as JSON: ' . json_last_error_msg(), 'INVALID_BODY');
            }
            $headers['Content-Type'] = 'application/json';
        }
        $decoded = json_decode($this->send($method, $this->at($path, $query), $body, $headers)->body, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Store bytes (a string or a readable stream). A path (/a/b/name, the
     * folder must exist) creates the file or replaces it; an xid or the file
     * replaces it; ['name' => ...] makes a new file in no folder. $ifMatch (a
     * hash, or the file) refuses with FILE_CHANGED when the file changed since.
     *
     * @param string|array<string,mixed> $target
     * @param string|resource $data
     * @param string|array{hash: ?string}|null $ifMatch
     * @return FileInfo
     */
    public function put(string|array $target, mixed $data, ?string $contentType = null, string|array|null $ifMatch = null): array
    {
        if (is_resource($data)) {
            $data = stream_get_contents($data);
            if ($data === false) {
                throw new SynthigyError('could not read the stream to put', 'INVALID_BODY');
            }
        }
        if (!is_string($data)) {
            throw new SynthigyError('put takes a string of bytes or a readable stream', 'INVALID_BODY');
        }
        // curl sends a body without a Content-Type as a form; the engine refuses forms
        $contentType ??= 'application/octet-stream';
        if (str_starts_with(strtolower($contentType), 'application/x-www-form-urlencoded')) {
            throw new SynthigyError(
                'a form-encoded body is parsed as a form, not stored — send the bytes with their own type',
                'UNSUPPORTED_MEDIA_TYPE',
            );
        }
        $method = 'PUT';
        $xid = is_array($target) ? (string)($target['xid'] ?? '') : $target;
        if ($xid !== '') {
            [$path, $query] = self::fileAt($xid);
        } elseif (is_array($target) && (string)($target['name'] ?? '') !== '') {
            [$method, $path, $query] = ['POST', '', ['name' => (string)$target['name']]];
        } else {
            throw new SynthigyError("put takes a path, an xid or file, or ['name' => ...] for a file in no folder", 'INVALID_PATH');
        }
        $expected = is_array($ifMatch) ? ($ifMatch['hash'] ?? null) : $ifMatch;
        $headers = ['Content-Type' => $contentType];
        if ($expected !== null && $expected !== '') {
            $headers['If-Match'] = '"' . $expected . '"';
        }
        /** @var FileInfo */
        return json_decode($this->send($method, $this->at($path, $query), $data, $headers, true)->body, true);
    }

    /**
     * The bytes of a file, or null while $ifNoneMatch still matches.
     * $range is [start, end], inclusive; null on either side leaves it open.
     *
     * @param string|array{xid: string} $file
     * @param array{0: ?int, 1?: ?int}|null $range
     * @param int|array{id: int}|null $version
     */
    public function download(string|array $file, ?array $range = null, ?string $ifNoneMatch = null, int|array|null $version = null): ?string
    {
        $headers = [];
        if ($range !== null) {
            $headers['Range'] = 'bytes=' . ($range[0] ?? '') . '-' . ($range[1] ?? '');
        }
        if ($ifNoneMatch !== null && $ifNoneMatch !== '') {
            $headers['If-None-Match'] = '"' . $ifNoneMatch . '"';
        }
        [$path, $query] = $version === null
            ? self::fileAt($file)
            : [self::seg($this->xidAt($file)) . '/versions' . self::seg(is_array($version) ? $version['id'] : $version), []];
        $resp = $this->send('GET', $this->at($path, $query), null, $headers, true);
        return $resp->status === 304 ? null : $resp->body;
    }

    /**
     * One zip (its bytes) of $files and of everything inside $folders, read as
     * you; what you cannot read is listed in its _skipped.txt.
     *
     * @param list<string|array{xid?: string, path?: string}> $files
     * @param list<string|array{xid?: string, path?: string}> $folders
     */
    public function zip(array $files = [], array $folders = []): string
    {
        $fileRef = static fn (string|array $f): string => is_array($f) ? (string)($f['xid'] ?? $f['path'] ?? '') : $f;
        $body = json_encode(['files' => array_map($fileRef, $files), 'folders' => array_map(self::folderRef(...), $folders)], JSON_THROW_ON_ERROR);
        return $this->send('POST', $this->at('/zip'), $body, ['Content-Type' => 'application/json'], true)->body;
    }

    /**
     * The file, or null when it is not there or you cannot see it.
     *
     * @param string|array{xid: string} $file
     * @return FileInfo|null
     */
    public function info(string|array $file): ?array
    {
        try {
            [$path, $query] = self::fileAt($file, '/info');
            /** @var FileInfo */
            return $this->json('GET', $path, null, $query);
        } catch (SynthigyError $e) {
            if ($e->code === 'FILE_NOT_FOUND' || $e->code === 'FOLDER_NOT_FOUND') {
                return null;
            }
            throw $e;
        }
    }

    /**
     * A folder's files and subfolders; null: the files in no folder; false
     * (the default): every file you can see, newest first. $q keeps names
     * holding its words in order; $limit defaults to 100, at most 1000.
     *
     * @param string|array{xid?: string, path?: string}|false|null $folder
     * @return array{files: list<FileInfo>, folders: list<FolderInfo>}
     */
    public function list(string|array|false|null $folder = false, ?string $q = null, ?int $limit = null, ?int $offset = null): array
    {
        $page = ['q' => $q, 'limit' => $limit, 'offset' => $offset];
        if ($folder === false || $folder === null) {
            /** @var list<FileInfo> $files */
            $files = $this->json('GET', '', null, $page + ['folderless' => $folder === null ? 'true' : null])['files'] ?? [];
            return ['files' => $files, 'folders' => []];
        }
        $ref = self::folderRef($folder);
        /** @var list<FileInfo> $files */
        $files = $this->json('GET', '', null, $page + ['folder' => $ref])['files'] ?? [];
        /** @var list<FolderInfo> $folders */
        $folders = $this->json('GET', '/folders', null, ['parent' => $ref])['folders'] ?? [];
        $words = preg_split('/\s+/', trim((string)$q), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($words !== []) {
            $holds = '/' . implode('.*', array_map(static fn ($w) => preg_quote($w, '/'), $words)) . '/iu';
            $folders = array_values(array_filter($folders, static fn ($f) => preg_match($holds, (string)($f['name'] ?? '')) === 1));
        }
        return ['files' => $files, 'folders' => $folders];
    }

    /**
     * Folders and files whose paths hold the words of $q in order, shortest
     * path first; $folder keeps it below that folder.
     *
     * @param string|array{xid?: string, path?: string}|null $folder
     * @return array{results: list<array<string,mixed>>, truncated: bool}
     */
    public function search(string $q, string|array|null $folder = null, ?int $limit = null, ?int $offset = null): array
    {
        /** @var array{results: list<array<string,mixed>>, truncated: bool} */
        return $this->json('GET', '/search', null, [
            'q' => $q,
            'folder' => $folder === null ? null : self::folderRef($folder),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Delete a file, or an empty folder (a path ending in / or the folder
     * itself). A file's bytes are kept for the grace period.
     *
     * @param string|array{xid?: string, path?: string} $ref
     */
    public function delete(string|array $ref): void
    {
        [$path, $query] = self::isFolder($ref) ? self::folderAt($ref) : self::fileAt($ref);
        $this->send('DELETE', $this->at($path, $query));
    }

    /**
     * Where a file loads from, without a request: /files/<xid>, or
     * /files/path/... for a path. $inline opens it in the browser instead of
     * downloading it.
     *
     * @param string|array{xid: string} $file
     */
    public function url(string|array $file, bool $inline = false): string
    {
        if (self::isPath($file)) {
            if (str_ends_with($file, '/')) {
                throw new SynthigyError('url takes a file, not a folder', 'INVALID_PATH');
            }
            $u = $this->endpoint . '/files/path' . implode('/', array_map('rawurlencode', explode('/', $file)));
        } else {
            $u = $this->endpoint . '/files' . self::seg(self::xid($file));
        }
        return $inline ? $u . '?disposition=inline' : $u;
    }

    /**
     * Rename or move. A file goes to $to (/a/new.png), or into a folder when
     * $to ends in / (the name stays); a folder's $to is its new path.
     *
     * @param string|array{xid?: string, path?: string} $from
     * @return FileInfo|FolderInfo
     */
    public function move(string|array $from, string $to): array
    {
        if (!self::isPath($to)) {
            throw new SynthigyError('move takes the new path, for example /site/brand/logo.png', 'INVALID_PATH');
        }
        if (self::isFolder($from)) {
            $parts = array_values(array_filter(explode('/', $to), static fn ($p) => $p !== ''));
            $name = array_pop($parts);
            if ($name === null) {
                throw new SynthigyError('a folder moves to a path like /archive/2026/', 'INVALID_PATH');
            }
            [$path, $query] = self::folderAt($from);
            /** @var FolderInfo */
            return $this->json('PATCH', $path, ['name' => $name, 'parent' => $parts === [] ? '/' : '/' . implode('/', $parts) . '/'], $query);
        }
        $cut = strrpos($to, '/');
        [$path, $query] = self::fileAt($from);
        $payload = str_ends_with($to, '/') ? ['folder' => $to] : ['folder' => substr($to, 0, $cut + 1), 'name' => substr($to, $cut + 1)];
        /** @var FileInfo */
        return $this->json('PATCH', $path, $payload, $query);
    }

    /**
     * The folder at $path, made with every missing folder above it.
     * $settings sets its own 'public', 'cache' and 'can_link' (null: inherit from above).
     *
     * @param array{public?: ?bool, cache?: ?int, can_link?: ?bool} $settings
     * @return FolderInfo
     */
    public function folder(string $path, array $settings = []): array
    {
        /** @var FolderInfo */
        return $this->json('PUT', '/folders', array_intersect_key($settings, ['public' => 1, 'cache' => 1, 'can_link' => 1]), ['path' => $path]);
    }

    /**
     * Give users and groups (xids or records) $access (read, write or delete)
     * on a folder; writers of the folder may.
     *
     * @param string|array{xid?: string, path?: string} $folder
     * @param list<string|array{xid: string}> $users
     * @param list<string|array{xid: string}> $groups
     */
    public function share(string|array $folder, string $access, array $users = [], array $groups = []): void
    {
        [$path, $query] = self::folderAt($folder, '/share');
        $this->json('POST', $path, self::grantees($access, $users, $groups), $query);
    }

    /**
     * Take $access on a folder away from users and groups.
     *
     * @param string|array{xid?: string, path?: string} $folder
     * @param list<string|array{xid: string}> $users
     * @param list<string|array{xid: string}> $groups
     */
    public function unshare(string|array $folder, string $access, array $users = [], array $groups = []): void
    {
        [$path, $query] = self::folderAt($folder, '/unshare');
        $this->json('POST', $path, self::grantees($access, $users, $groups), $query);
    }

    /**
     * @param list<string|array{xid: string}> $users
     * @param list<string|array{xid: string}> $groups
     * @return array{access: string, users: list<string>, groups: list<string>}
     */
    private static function grantees(string $access, array $users, array $groups): array
    {
        return [
            'access' => $access,
            'users' => array_map(self::xid(...), $users),
            'groups' => array_map(self::xid(...), $groups),
        ];
    }

    /**
     * Who holds read, write and delete on a folder.
     *
     * @param string|array{xid?: string, path?: string} $folder
     * @return array<string, array{users: list<array{xid: string, name: ?string}>, groups: list<array{xid: string, name: ?string}>}>
     */
    public function access(string|array $folder): array
    {
        [$path, $query] = self::folderAt($folder, '/access');
        /** @var array<string, array{users: list<array{xid: string, name: ?string}>, groups: list<array{xid: string, name: ?string}>}> */
        return $this->json('GET', $path, null, $query);
    }

    /**
     * The kept versions of a file (the last ten), newest first.
     *
     * @param string|array{xid: string} $file
     * @return list<array{id: int, at: string, by: ?string, hash: ?string, size: ?int, content_type: ?string, current: bool}>
     */
    public function versions(string|array $file): array
    {
        /** @var list<array{id: int, at: string, by: ?string, hash: ?string, size: ?int, content_type: ?string, current: bool}> */
        return $this->json('GET', self::seg($this->xidAt($file)) . '/versions')['versions'] ?? [];
    }

    /**
     * Make a kept version current again; no bytes are copied.
     *
     * @param string|array{xid: string} $file
     * @param int|array{id: int} $version
     * @return FileInfo
     */
    public function restore(string|array $file, int|array $version): array
    {
        /** @var FileInfo */
        return $this->json('POST', self::seg($this->xidAt($file)) . '/versions' . self::seg(is_array($version) ? $version['id'] : $version) . '/restore');
    }

    /**
     * A link that downloads the file without signing in, in folders where
     * links are on: $expires seconds (default 3600, at most 7 days),
     * $maxDownloads, $inline. Its 'url' is shown only now.
     *
     * @param string|array{xid: string} $file
     * @return FileLink
     */
    public function link(string|array $file, ?int $expires = null, ?int $maxDownloads = null, ?bool $inline = null): array
    {
        $opts = array_filter(['expires' => $expires, 'max_downloads' => $maxDownloads, 'inline' => $inline], static fn ($v) => $v !== null);
        /** @var FileLink */
        return $this->json('POST', self::seg($this->xidAt($file)) . '/links', $opts);
    }

    /**
     * The file's live links you can see: your own, or all of them for File Admin.
     *
     * @param string|array{xid: string} $file
     * @return list<FileLink>
     */
    public function links(string|array $file): array
    {
        /** @var list<FileLink> */
        return $this->json('GET', self::seg($this->xidAt($file)) . '/links')['links'] ?? [];
    }

    /**
     * End a link (the link or its xid) at once, on every engine.
     *
     * @param string|array{xid: string} $link
     */
    public function unlink(string|array $link): void
    {
        $this->send('DELETE', $this->at('/links' . self::seg(self::xid($link))));
    }

    /**
     * Answer a browser's request with a file. Range, If-None-Match and
     * If-Range from $headers go through, and the answer keeps status, type,
     * length, ETag and name; a refusal is the engine's own answer. Our own
     * credentials failing (UNAUTHORIZED) or the network throws.
     *
     * @param string|array{xid: string} $file
     * @param array<string,string> $headers the browser's request headers
     */
    public function serve(string|array $file, array $headers = [], string $method = 'GET', bool $inline = false): HttpResponse
    {
        $incoming = array_change_key_case($headers, CASE_LOWER);
        $pass = [];
        foreach (['Range', 'If-None-Match', 'If-Range'] as $k) {
            if (($incoming[strtolower($k)] ?? '') !== '') {
                $pass[$k] = $incoming[strtolower($k)];
            }
        }
        [$path, $query] = self::fileAt($file);
        if ($inline) {
            $query['disposition'] = 'inline';
        }
        $head = strtoupper($method) === 'HEAD';
        $resp = ($this->request)($head ? 'HEAD' : 'GET', $this->at($path, $query), null, $pass, true);
        return new HttpResponse($resp->status, array_intersect_key($resp->headers, array_flip(self::SERVED)), $head ? '' : $resp->body);
    }
}
