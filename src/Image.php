<?php

declare(strict_types=1);

namespace G\Image;

use G\Image\Driver\DriverAvailabilityInterface;
use G\Image\Driver\DriverInterface;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\Factory\ImageFactory;
use G\Image\Factory\ImageFactoryInterface;

/**
 * Static facade providing the main entry point of the library.
 *
 * Usage:
 * ```php
 * use G\Image\Image;
 * use G\Image\Driver\GDDriver;
 *
 * $image = Image::create(GDDriver::class)
 *     ->fromFile('/path/to/photo.jpg')
 *     ->resize(800, null)
 *     ->toWebp(85)
 *     ->save('/path/to/output.webp');
 * ```
 *
 * The facade holds no state. To swap the factory (DI container, test double),
 * depend on {@see ImageFactoryInterface} and inject {@see ImageFactory} or your
 * own implementation instead of calling the facade.
 */
final class Image
{
    /**
     * Prevent direct instantiation.
     *
     * @codeCoverageIgnore
     */
    private function __construct()
    {
    }

    /**
     * Create and return a new driver instance.
     *
     * For IDE type-narrowing on the concrete driver type, use
     * {@see ImageFactory::create()} directly:
     * ```php
     * $driver = (new ImageFactory())->create(GDDriver::class); // typed as GDDriver
     * ```
     *
     * @param class-string<DriverInterface> $driverClass Fully qualified driver class name.
     * @return DriverInterface
     */
    public static function create(string $driverClass): DriverInterface
    {
        return (new ImageFactory())->create($driverClass);
    }

    /**
     * Create the first available driver from an ordered list of candidates.
     *
     * Each class is checked via its {@see DriverAvailabilityInterface::isAvailable()}
     * static method before being instantiated, so a consumer never needs to
     * duplicate a driver's own extension-loaded check (e.g. `extension_loaded('gd')`).
     *
     * ```php
     * $image = Image::createFirstAvailable([GDDriver::class, ImagickDriver::class])
     *     ->fromFile($upload);
     * ```
     *
     * @param list<class-string<DriverInterface>> $driverClasses Ordered candidates, tried in order.
     * @return DriverInterface The first available driver, instantiated.
     * @throws UnsupportedOperationException When none of the candidates is available.
     */
    public static function createFirstAvailable(array $driverClasses): DriverInterface
    {
        foreach ($driverClasses as $driverClass) {
            $isAvailable = is_a($driverClass, DriverAvailabilityInterface::class, allow_string: true)
                && $driverClass::isAvailable();

            if ($isAvailable) {
                return self::create($driverClass);
            }
        }

        throw new UnsupportedOperationException(
            sprintf(
                'None of the candidate drivers is available on this system: %s.',
                implode(', ', $driverClasses),
            )
        );
    }
}
