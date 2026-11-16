<?php

declare(strict_types=1);

namespace G\Image\Tests;

use G\Image\Driver\DriverInterface;
use G\Image\Driver\GDDriver;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\Factory\ImageFactoryInterface;
use G\Image\Image;
use G\Image\Tests\Fixture\UnavailableFakeDriver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(Image::class)]
#[RequiresPhpExtension('gd')]
final class ImageTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset static factory state after every test.
        Image::setFactory(null);
    }

    public function testCreateReturnsDriverInstanceWithDefaultFactory(): void
    {
        $driver = Image::create(GDDriver::class);

        self::assertInstanceOf(GDDriver::class, $driver);
    }

    public function testSetFactoryOverridesDefaultForSubsequentCreates(): void
    {
        $expectedDriver = new GDDriver();

        $mockFactory = new class ($expectedDriver) implements ImageFactoryInterface {
            public function __construct(private readonly DriverInterface $driver) {}

            public function create(string $driverClass): DriverInterface
            {
                return $this->driver;
            }
        };

        Image::setFactory($mockFactory);
        $result = Image::create(GDDriver::class);

        self::assertSame($expectedDriver, $result);
    }

    public function testSetFactoryNullResetsToDefaultFactory(): void
    {
        // Override factory, then reset.
        $mockFactory = new class implements ImageFactoryInterface {
            public function create(string $driverClass): DriverInterface
            {
                return new GDDriver();
            }
        };

        Image::setFactory($mockFactory);
        Image::setFactory(null);

        // After reset, the default ImageFactory should be used.
        $driver = Image::create(GDDriver::class);
        self::assertInstanceOf(GDDriver::class, $driver);
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
