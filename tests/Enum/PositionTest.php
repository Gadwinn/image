<?php

declare(strict_types=1);

namespace G\Image\Tests\Enum;

use G\Image\Enum\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Position::class)]
final class PositionTest extends TestCase
{
    // =========================================================================
    // toOffset — corner anchors
    // =========================================================================

    public function testTopLeftReturnsZeroZero(): void
    {
        [$x, $y] = Position::TOP_LEFT->toOffset(200, 100, 50, 30);

        self::assertSame(0, $x);
        self::assertSame(0, $y);
    }

    public function testTopRightReturnsRightEdge(): void
    {
        [$x, $y] = Position::TOP_RIGHT->toOffset(200, 100, 50, 30);

        self::assertSame(150, $x); // 200 - 50
        self::assertSame(0,   $y);
    }

    public function testBottomLeftReturnsBottomEdge(): void
    {
        [$x, $y] = Position::BOTTOM_LEFT->toOffset(200, 100, 50, 30);

        self::assertSame(0,  $x);
        self::assertSame(70, $y); // 100 - 30
    }

    public function testBottomRightReturnsBottomRightCorner(): void
    {
        [$x, $y] = Position::BOTTOM_RIGHT->toOffset(200, 100, 50, 30);

        self::assertSame(150, $x); // 200 - 50
        self::assertSame(70,  $y); // 100 - 30
    }

    // =========================================================================
    // toOffset — center anchors
    // =========================================================================

    public function testMiddleCenterReturnsCenteredOffset(): void
    {
        [$x, $y] = Position::MIDDLE_CENTER->toOffset(200, 100, 50, 30);

        self::assertSame(75, $x); // (200 - 50) / 2
        self::assertSame(35, $y); // (100 - 30) / 2
    }

    public function testTopCenterReturnsCenteredX(): void
    {
        [$x, $y] = Position::TOP_CENTER->toOffset(200, 100, 50, 30);

        self::assertSame(75, $x); // (200 - 50) / 2
        self::assertSame(0,  $y);
    }

    public function testBottomCenterReturnsCenteredXAndBottom(): void
    {
        [$x, $y] = Position::BOTTOM_CENTER->toOffset(200, 100, 50, 30);

        self::assertSame(75, $x); // (200 - 50) / 2
        self::assertSame(70, $y); // 100 - 30
    }

    public function testMiddleLeftReturnsZeroXAndCenteredY(): void
    {
        [$x, $y] = Position::MIDDLE_LEFT->toOffset(200, 100, 50, 30);

        self::assertSame(0,  $x);
        self::assertSame(35, $y); // (100 - 30) / 2
    }

    public function testMiddleRightReturnsRightXAndCenteredY(): void
    {
        [$x, $y] = Position::MIDDLE_RIGHT->toOffset(200, 100, 50, 30);

        self::assertSame(150, $x); // 200 - 50
        self::assertSame(35,  $y); // (100 - 30) / 2
    }

    // =========================================================================
    // toOffset — edge cases
    // =========================================================================

    public function testOffsetCanBeNegativeWhenElementLargerThanContainer(): void
    {
        [$x, $y] = Position::MIDDLE_CENTER->toOffset(50, 50, 200, 200);

        self::assertSame(-75, $x); // (50 - 200) / 2
        self::assertSame(-75, $y);
    }

    public function testOffsetIsZeroWhenElementMatchesContainer(): void
    {
        [$x, $y] = Position::MIDDLE_CENTER->toOffset(100, 100, 100, 100);

        self::assertSame(0, $x);
        self::assertSame(0, $y);
    }
}
