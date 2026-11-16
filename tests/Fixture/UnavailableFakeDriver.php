<?php

declare(strict_types=1);

namespace G\Image\Tests\Fixture;

use G\Image\Driver\DriverAvailabilityInterface;

/**
 * Test double simulating a driver whose runtime requirements are never met.
 *
 * Used to exercise {@see \G\Image\Image::createFirstAvailable()}'s
 * "skip unavailable candidates" branch without depending on an actual
 * missing PHP extension.
 */
final class UnavailableFakeDriver implements DriverAvailabilityInterface
{
    public static function isAvailable(): bool
    {
        return false;
    }
}
