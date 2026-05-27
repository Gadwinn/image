<?php

declare(strict_types=1);

namespace G\Image\Factory;

use G\Image\Driver\DriverInterface;

/**
 * Contract for instantiating image driver instances.
 *
 * @template T of DriverInterface
 */
interface ImageFactoryInterface
{
    /**
     * Create and return a new driver instance for the given class.
     *
     * @param class-string<T> $driverClass Fully qualified class name of the driver to instantiate.
     * @return T A ready-to-use driver instance.
     */
    public function create(string $driverClass): DriverInterface;
}
