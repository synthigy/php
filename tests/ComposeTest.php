<?php

declare(strict_types=1);

namespace Synthigy\Tests;

use PHPUnit\Framework\TestCase;

use function Synthigy\composeForest;
use function Synthigy\composeTree;

final class ComposeTest extends TestCase
{
    public function testComposeTreeNestsChildren(): void
    {
        $records = [
            ['xid' => 'a', 'parent' => null, 'name' => 'root'],
            ['xid' => 'b', 'parent' => 'a', 'name' => 'child1'],
            ['xid' => 'c', 'parent' => 'a', 'name' => 'child2'],
            ['xid' => 'd', 'parent' => 'b', 'name' => 'grandchild'],
        ];

        $tree = composeTree($records, 'parent');

        self::assertNotNull($tree);
        self::assertSame('a', $tree['xid']);
        self::assertCount(2, $tree['_children']);
        $child1 = $tree['_children'][0];
        self::assertSame('b', $child1['xid']);
        self::assertCount(1, $child1['_children']);
        self::assertSame('d', $child1['_children'][0]['xid']);
    }

    public function testComposeTreeReturnsNullWhenRootMissing(): void
    {
        self::assertNull(composeTree([['xid' => 'a', 'parent' => null]], 'parent', 'missing'));
    }

    public function testComposeTreeReturnsNullOnEmptyInput(): void
    {
        self::assertNull(composeTree([], 'parent'));
    }

    public function testComposeForestGroupsMultipleRoots(): void
    {
        $records = [
            ['xid' => 'a', 'parent' => null],
            ['xid' => 'b', 'parent' => 'a'],
            ['xid' => 'x', 'parent' => null],
            ['xid' => 'y', 'parent' => 'x'],
        ];

        $forest = composeForest($records, 'parent');

        self::assertCount(2, $forest);
        self::assertSame('a', $forest[0]['xid']);
        self::assertSame('b', $forest[0]['_children'][0]['xid']);
        self::assertSame('x', $forest[1]['xid']);
    }

    public function testComposeForestTreatsMissingParentAsRoot(): void
    {
        // parent xid not present in the set -> treated as its own root.
        $records = [
            ['xid' => 'b', 'parent' => 'a-not-in-set'],
        ];
        $forest = composeForest($records, 'parent');
        self::assertCount(1, $forest);
        self::assertSame('b', $forest[0]['xid']);
    }

    public function testCycleIsBrokenNotInfiniteLoop(): void
    {
        // a -> b -> a (a cycle). Both drop from the branch they'd loop in;
        // composeForest still terminates and returns something sane.
        $records = [
            ['xid' => 'a', 'parent' => 'b'],
            ['xid' => 'b', 'parent' => 'a'],
        ];
        $forest = composeForest($records, 'parent');
        // Every record has a parent present in the set, so no root
        // qualifies — the forest is empty, and critically, this returns
        // rather than looping forever.
        self::assertSame([], $forest);
    }

    public function testRelationKeyToleratesCasingVariants(): void
    {
        $records = [
            ['xid' => 'a', 'parent_id' => null],
            ['xid' => 'b', 'parent-id' => 'a'],
        ];
        $tree = composeTree($records, 'parent-id');
        self::assertNotNull($tree);
        self::assertSame('a', $tree['xid']);
        self::assertSame('b', $tree['_children'][0]['xid']);
    }

    public function testChildrenKeyIsConfigurable(): void
    {
        $records = [
            ['xid' => 'a', 'parent' => null],
            ['xid' => 'b', 'parent' => 'a'],
        ];
        $tree = composeTree($records, 'parent', childrenKey: 'kids');
        self::assertNotNull($tree);
        self::assertArrayHasKey('kids', $tree);
        self::assertArrayNotHasKey('_children', $tree);
    }
}
