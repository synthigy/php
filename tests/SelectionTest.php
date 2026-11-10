<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;

use function Synthigy\fields;
use function Synthigy\normalizeSelection;
use function Synthigy\rel;

final class SelectionTest extends TestCase
{
    public function testNullAndTruePassThrough(): void
    {
        self::assertNull(normalizeSelection(null));
        self::assertNull(normalizeSelection(true));
    }

    public function testStringListBecomesNullMap(): void
    {
        self::assertSame(
            ['name' => null, 'email' => null],
            normalizeSelection(['name', 'email']),
        );
    }

    public function testFieldsHelper(): void
    {
        self::assertSame(['a' => null, 'b' => null], fields('a', 'b'));
    }

    public function testNestedRelationArrayWraps(): void
    {
        self::assertSame(
            ['roles' => [['selections' => ['name' => null]]]],
            normalizeSelection(['roles' => ['name' => null]]),
        );
    }

    public function testNestedRelationStringListWraps(): void
    {
        self::assertSame(
            ['roles' => [['selections' => ['name' => null, 'id' => null]]]],
            normalizeSelection(['roles' => ['name', 'id']]),
        );
    }

    public function testRelHelperWithArgsAndAlias(): void
    {
        self::assertSame(
            ['selections' => ['name' => null], 'args' => ['_limit' => 5], 'alias' => 'top'],
            rel(['name' => null], ['_limit' => 5], 'top'),
        );
    }

    public function testRelWithoutArgsOmitsArgsKey(): void
    {
        self::assertSame(['selections' => ['name' => null]], rel(['name' => null]));
    }

    public function testRepeatedRelationPassesThroughNormalized(): void
    {
        $result = normalizeSelection([
            'roles' => [
                rel(['name' => null], ['_limit' => 1], 'first'),
                rel(['name' => null], ['_limit' => 1], 'second'),
            ],
        ]);
        self::assertSame([
            'roles' => [
                ['selections' => ['name' => null], 'args' => ['_limit' => 1], 'alias' => 'first'],
                ['selections' => ['name' => null], 'args' => ['_limit' => 1], 'alias' => 'second'],
            ],
        ], $result);
    }

    public function testOperationEntriesPassThrough(): void
    {
        $ops = [
            ['alias' => 'votes', 'op' => 'count'],
            ['alias' => 'rating', 'op' => ['avg' => 'value']],
        ];
        self::assertSame(
            ['title' => null, 'ratings' => $ops],
            normalizeSelection(['title' => null, 'ratings' => $ops]),
        );
    }

    public function testScalarValuePassesThroughUnchanged(): void
    {
        self::assertSame('not-a-selection', normalizeSelection('not-a-selection'));
    }
}
