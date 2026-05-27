<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when an image operation is not supported by the active driver.
 *
 * Not every driver implements every operation (e.g. GD has no native EXIF
 * write support, VIPS handles animations differently from Imagick).
 * This exception signals that the requested method exists in the interface
 * but cannot be fulfilled by the current driver at runtime.
 *
 * Callers can probe operation support before attempting an operation via
 * {@see \G\Image\Driver\CapabilityInterface::supportsOperation()}.
 */
final class UnsupportedOperationException extends ImageException
{
}
