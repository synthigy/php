<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\Codegen\CodegenError;

use function Synthigy\Codegen\render;
use function Synthigy\Codegen\resolveMember;
use function Synthigy\Codegen\validateOps;

final class CodegenTest extends TestCase
{
    /** @return array<string,mixed> a small hand-authored IR, shaped like a real op:"describe" response */
    private function sampleIr(): array
    {
        return [
            'sourceHash' => 'deadbeef',
            'operations' => [
                [
                    'name' => 'top_movies',
                    'op' => 'search',
                    'entity' => 'movie',
                    'namespace' => 'Movies',
                    'params' => [
                        ['name' => 'since', 'type' => 'int', 'optional' => true],
                    ],
                    'source' => "movie (release_year > ?since:int=1980)\n  xid\n  title\n  genres\n    name",
                    'result' => [
                        'kind' => 'list',
                        'fields' => [
                            ['key' => 'xid', 'type' => 'uuid', 'nullable' => false],
                            ['key' => 'title', 'type' => 'string', 'nullable' => false],
                            ['key' => 'genres', 'kind' => 'relation', 'cardinality' => 'many', 'fields' => [
                                ['key' => 'name', 'type' => 'string', 'nullable' => false],
                            ]],
                        ],
                    ],
                ],
                [
                    'name' => 'movie_detail',
                    'op' => 'get',
                    'entity' => 'movie',
                    'namespace' => 'Movies',
                    'params' => [],
                    'source' => "movie (xid = ?xid:string)\n  xid\n  title",
                    'result' => [
                        'kind' => 'single',
                        'fields' => [
                            ['key' => 'xid', 'type' => 'uuid', 'nullable' => false],
                            ['key' => 'title', 'type' => 'string', 'nullable' => false],
                        ],
                    ],
                ],
                [
                    'name' => 'movie_count',
                    'op' => 'sql-template',
                    'entity' => 'movie',
                    'namespace' => 'Movies',
                    'params' => [
                        ['name' => 'since', 'type' => 'int', 'optional' => true],
                    ],
                    'source' => "SELECT count(*) AS total FROM {movie}\nWHERE release_year > ?since:int=1995",
                    'result' => [
                        'kind' => 'list',
                        'fields' => [
                            ['key' => 'total', 'type' => 'int', 'nullable' => false],
                        ],
                    ],
                ],
                [
                    'name' => 'overview',
                    'batch' => true,
                    'members' => ['top_movies', 'movie_detail', 'movie_count'],
                ],
                [
                    'name' => 'sync_movie',
                    'op' => 'sync',
                    'entity' => 'movie',
                ],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function sampleSchema(): array
    {
        return [
            'version' => 'v1',
            'entities' => [
                'movie' => [
                    'skins' => ['pascal' => 'Movie'],
                    'attributes' => [
                        'xid' => ['type' => 'uuid'],
                        'title' => ['type' => 'string', 'nullable' => false],
                        'release_year' => ['type' => 'int'],
                    ],
                    'relations' => [
                        'genres' => ['to' => 'genre', 'cardinality' => 'many'],
                    ],
                ],
                'genre' => [
                    'skins' => ['pascal' => 'Genre'],
                    'attributes' => [
                        'xid' => ['type' => 'uuid'],
                        'name' => ['type' => 'string', 'nullable' => false],
                    ],
                    'relations' => [],
                ],
            ],
        ];
    }

    public function testValidateOpsSkipsMutationsAndKeepsReads(): void
    {
        [$ops, $batches] = validateOps($this->sampleIr()['operations']);
        $names = array_map(static fn($o) => $o['name'], $ops);
        self::assertSame(['top_movies', 'movie_detail', 'movie_count'], $names);
        self::assertCount(1, $batches);
    }

    public function testValidateOpsRejectsUnknownVerb(): void
    {
        $this->expectException(CodegenError::class);
        validateOps([['name' => 'x', 'op' => 'aggregate', 'entity' => 'movie']]);
    }

    public function testResolveMemberByBareName(): void
    {
        [$ops] = validateOps($this->sampleIr()['operations']);
        $m = resolveMember($ops, 'overview', 'top_movies');
        self::assertSame('top_movies', $m['name']);
    }

    public function testResolveMemberUnknownRaises(): void
    {
        [$ops] = validateOps($this->sampleIr()['operations']);
        $this->expectException(CodegenError::class);
        resolveMember($ops, 'overview', 'nope');
    }

    public function testRenderProducesSyntacticallyValidPhp(): void
    {
        $code = render($this->sampleIr(), $this->sampleSchema(), true, 'Generated\\Movies', 'movies.xsql');

        $tmp = tempnam(sys_get_temp_dir(), 'synthigy_codegen_') . '.php';
        file_put_contents($tmp, $code);
        try {
            $lint = shell_exec('php -l ' . escapeshellarg($tmp) . ' 2>&1');
            self::assertIsString($lint);
            self::assertStringContainsString('No syntax errors detected', $lint, $code);
        } finally {
            unlink($tmp);
        }
    }

    public function testRenderIncludesExpectedShapes(): void
    {
        $code = render($this->sampleIr(), $this->sampleSchema(), true, 'Generated\\Movies', 'movies.xsql');

        self::assertStringContainsString('final class Movies', $code);
        self::assertStringContainsString('public static function topMovies(', $code);
        self::assertStringContainsString('public static function movieDetail(', $code);
        self::assertStringContainsString('final class Batches', $code);
        self::assertStringContainsString('public static function overview(', $code);
        self::assertStringContainsString('final class Writes', $code);
        self::assertStringContainsString('public static function syncMovie(', $code);
        self::assertStringContainsString('public static function stackMovie(', $code);
        self::assertStringContainsString('public static function deleteMovie(', $code);
        // the mutation op in the IR (sync_movie) must be skipped, not rendered as a read method
        self::assertStringNotContainsString('function syncMovie(array $params', $code);
        // relation field is optional (wire omits empty relations)
        self::assertStringContainsString('genres?:', $code);
    }

    public function testSqlTemplateOpsRouteThroughSqlTemplateNotQuery(): void
    {
        // A sql-template op's `source` is raw SQL, never an XSQL document —
        // routing it through query()/opQuery() sends it to the XSQL parser
        // and fails live with XSQL_PARSE_ERROR (caught against a real
        // server, not by an earlier version of this test suite).
        $code = render($this->sampleIr(), $this->sampleSchema(), true, 'Generated\\Movies', 'movies.xsql');

        self::assertStringContainsString('public static function movieCount(', $code);
        self::assertMatchesRegularExpression(
            '/movieCount\([^)]*\)\s*:\s*array\s*\{\s*\$data = \\\\Synthigy\\\\Synthigy::sqlTemplate\(/',
            $code,
        );
        self::assertStringNotContainsString('Synthigy::query(_SRC_MOVIES_MOVIE_COUNT', $code);
        // the batch member for the sql-template op must build via opSqlTemplate, not opQuery
        self::assertStringContainsString('\Synthigy\opSqlTemplate(_SRC_MOVIES_MOVIE_COUNT, $params)', $code);
    }

    public function testRenderWithoutWritesOmitsWritesClass(): void
    {
        $code = render($this->sampleIr(), null, false, 'Generated\\Movies', 'movies.xsql');
        self::assertStringNotContainsString('final class Writes', $code);
    }

    public function testRenderWritesRequiresSchema(): void
    {
        $this->expectException(CodegenError::class);
        render($this->sampleIr(), null, true, 'Generated\\Movies', 'movies.xsql');
    }
}
