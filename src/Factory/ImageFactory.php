<?php

declare(strict_types=1);

namespace G\Image\Factory;

use G\Image\Driver\DriverInterface;
use G\Image\Exception\InvalidImageArgumentException;

/**
 * Default factory that instantiates driver classes via reflection-free
 * dynamic instantiation.
 *
 * The driver class must have a public no-argument constructor.
 * If the driver requires dependencies, extend this class or provide a custom
 * {@see ImageFactoryInterface} implementation via {@see \G\Image\Image::setFactory()}.
 *
 * @template T of DriverInterface
 * @implements ImageFactoryInterface<T>
 */
final class ImageFactory implements ImageFactoryInterface
{
    /**
     * @param class-string<T> $driverClass
     * @return T
     */
    public function create(string $driverClass): DriverInterface
    {
        if (!class_exists($driverClass)) {
            throw new InvalidImageArgumentException(sprintf(
                'Driver class "%s" does not exist or could not be autoloaded.',
                $driverClass,
            ));
        }

        /** @var T $driver */
        $driver = new $driverClass();

        return $driver;
    }
}
