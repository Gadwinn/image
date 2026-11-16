<?php

declare(strict_types=1);

namespace G\Image\Driver;

/**
 * Static contract for checking whether a driver can be instantiated at all,
 * before attempting to construct it.
 *
 * Kept separate from {@see DriverInterface} on purpose: DriverInterface is an
 * instance-level contract describing what an already-constructed driver can
 * do, while availability must be knowable precisely when no instance exists
 * yet (missing extension). Merging the two would force every instance-based
 * consumer (mocks, stubs, test doubles) to also implement a static method
 * that is meaningless to them.
 *
 * Concrete driver classes MUST implement this interface alongside
 * {@see DriverInterface}.
 */
interface DriverAvailabilityInterface
{
    /**
     * Return whether this driver's runtime requirements (PHP extensions,
     * minimum extension versions, etc.) are met on the current system.
     *
     * @return bool True when {@see \G\Image\Factory\ImageFactoryInterface::create()}
     *              can be expected to succeed for this driver class.
     */
    public static function isAvailable(): bool;
}
