<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when a requested image format is not supported by the active driver.
 *
 * This is a runtime exception because format support depends on which PHP
 * extensions are installed (e.g. AVIF requires GD compiled with libavif,
 * HEIC requires Imagick with the heic delegate).
 *
 * Callers can probe format support before attempting conversion via
 * {@see \G\Image\Driver\CapabilityInterface::supportsFormat()}.
 */
final class UnsupportedFormatException extends ImageException
{
}
