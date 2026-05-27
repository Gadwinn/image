<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when an image cannot be loaded from a file, URL, binary string,
 * PHP resource, or PSR-7 stream.
 *
 * Common causes:
 *   - file does not exist or is not readable
 *   - URL is unreachable or returns a non-image response
 *   - binary data is not a recognized image format
 *   - PHP resource is not a valid image resource for the current driver
 */
final class ImageLoadException extends ImageException
{
}
