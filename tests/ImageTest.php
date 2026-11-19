<?php

declare(strict_types=1);

namespace G\Image\Tests;

use G\Image\Driver\GDDriver;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\Image;
use G\Image\Tests\Fixture\UnavailableFakeDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(Image::class)]
#[RequiresPhpExtension('gd')]
final class ImageTest extends TestCase
{
    public function testCreateReturnsDriverInstanceWithDefaultFactory(): void
    {
        $driver = Image::create(GDDriver::class);

        self::assertInstanceOf(GDDriver::class, $driver);
    }

    public function testCreateReturnsANewDriverOnEveryCall(): void
    {
        self::assertNotSame(Image::create(GDDriver::class), Image::create(GDDriver::class));
    }

    public function testCreateFirstAvailableReturnsFirstAvailableDriver(): void
    {
        $driver = Image::createFirstAvailable([GDDriver::class]);

        self::assertInstanceOf(GDDriver::class, $driver);
    }

    public function testCreateFirstAvailableSkipsUnavailableCandidates(): void
    {
        $driver = Image::createFirstAvailable([UnavailableFakeDriver::class, GDDriver::class]);

        self::assertInstanceOf(GDDriver::class, $driver);
    }

    public function testCreateFirstAvailableThrowsWhenNoneAvailable(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        Image::createFirstAvailable([UnavailableFakeDriver::class]);
    }
}
