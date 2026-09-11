<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;

use function Synthigy\and_;
use function Synthigy\eq;
use function Synthigy\gt;
use function Synthigy\gte;
use function Synthigy\ilike;
use function Synthigy\in_;
use function Synthigy\isNotNull;
use function Synthigy\isNull;
use function Synthigy\like;
use function Synthigy\lt;
use function Synthigy\lte;
use function Synthigy\neq;
use function Synthigy\nin;
use function Synthigy\not_;
use function Synthigy\or_;

final class FiltersTest extends TestCase
{
    public function testSimpleOperators(): void
    {
        self::assertSame(['_eq' => 5], eq(5));
        self::assertSame(['_neq' => 5], neq(5));
        self::assertSame(['_gt' => 5], gt(5));
        self::assertSame(['_lt' => 5], lt(5));
        self::assertSame(['_like' => '%a%'], like('%a%'));
        self::assertSame(['_ilike' => '%a%'], ilike('%a%'));
        self::assertSame(['_is_null' => true], isNull());
        self::assertSame(['_is_not_null' => true], isNotNull());
    }

    public function testGteLteWireMappingIsGeLe(): void
    {
        self::assertSame(['_ge' => 18], gte(18));
        self::assertSame(['_le' => 65], lte(65));
    }

    public function testInVariadicAndArrayFormsAreEquivalent(): void
    {
        self::assertSame(['_in' => [1, 2, 3]], in_(1, 2, 3));
        self::assertSame(['_in' => [1, 2, 3]], in_([1, 2, 3]));
        self::assertSame(['_nin' => ['a', 'b']], nin('a', 'b'));
    }

    public function testAndOrKeepClausesWhole(): void
    {
        $c1 = eq(1);
        $c2 = gt(0);
        self::assertSame(['_and' => [$c1, $c2]], and_($c1, $c2));
        self::assertSame(['_or' => [$c1, $c2]], or_([$c1, $c2]));
        self::assertSame(['_not' => $c1], not_($c1));
    }
}
