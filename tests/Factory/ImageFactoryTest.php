<?php

declare(strict_types=1);

namespace G\Image\Tests\Factory;

use G\Image\Driver\GDDriver;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Factory\ImageFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(ImageFactory::class)]
#[RequiresPhpExtension('gd')]
final class ImageFactoryTest extends TestCase
{
    private ImageFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new ImageFactory();
    }

    public function testCreateReturnsDriverInstanceForKnownClass(): void
    {
        $driver = $this->factory->create(GDDriver::class);

        self::assertInstanceOf(GDDriver::class, $driver);
    }

    public function testCreateThrowsForNonExistentDriverClass(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        // @phpstan-ignore argument.type
        $this->factory->create('G\\Image\\Driver\\NonExistentDriver12345');
    }
}
