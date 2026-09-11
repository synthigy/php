<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;
use Synthigy\SynthigyError;

final class ErrorsTest extends TestCase
{
    public function testKnownCodeGetsItsCategory(): void
    {
        $e = new SynthigyError('nope', 'FORBIDDEN');
        self::assertSame('iam', $e->category);
        self::assertFalse($e->retryable);
    }

    public function testUnknownCodeFallsBackToInternal(): void
    {
        $e = new SynthigyError('mystery', 'SOME_FUTURE_CODE');
        self::assertSame('internal', $e->category);
        self::assertTrue($e->retryable);
    }

    public function testNetworkAndRateLimitAndInternalAreRetryable(): void
    {
        self::assertTrue((new SynthigyError('x', 'NETWORK_ERROR'))->retryable);
        self::assertTrue((new SynthigyError('x', 'INTERNAL_ERROR'))->retryable);
        self::assertFalse((new SynthigyError('x', 'NOT_CONNECTED'))->retryable);
    }

    public function testFromServerCopiesStructuredFields(): void
    {
        $e = SynthigyError::fromServer([
            'message' => 'bad query',
            'code' => 'XSQL_PARSE_ERROR',
            'line' => 3,
            'col' => 7,
            'hint' => 'did you mean X?',
        ], 400, 'req-123');

        self::assertSame('bad query', $e->getMessage());
        self::assertSame('XSQL_PARSE_ERROR', $e->code);
        self::assertSame('validation', $e->category);
        self::assertSame(3, $e->errorLine);
        self::assertSame(7, $e->col);
        self::assertSame('did you mean X?', $e->hint);
        self::assertSame(400, $e->status);
        self::assertSame('req-123', $e->requestId);
    }

    public function testFromServerNullDefaultsToInternal(): void
    {
        $e = SynthigyError::fromServer(null);
        self::assertSame('INTERNAL_ERROR', $e->code);
        self::assertSame('internal', $e->category);
    }

    public function testToStringIncludesCode(): void
    {
        $e = new SynthigyError('boom', 'FORBIDDEN');
        self::assertStringContainsString('boom', (string)$e);
        self::assertStringContainsString('FORBIDDEN', (string)$e);
    }
}
