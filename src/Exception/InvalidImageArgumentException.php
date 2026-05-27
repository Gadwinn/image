<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when an argument passed to an image operation is invalid.
 *
 * This is the package-specific replacement for PHP's built-in
 * \InvalidArgumentException. Use it instead of the native class so that
 * consumers can catch all image-related errors via {@see ImageExceptionInterface}.
 *
 * Common causes:
 *   - negative or zero dimensions for resize/crop/create
 *   - quality/compression values outside their valid range
 *   - empty or null values where a non-empty value is required
 *   - incompatible argument combinations (e.g. both $horizontal and $vertical
 *     set to false on a flip operation)
 */
final class InvalidImageArgumentException extends ImageLogicException
{
}
