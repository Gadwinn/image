<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Marker interface for all exceptions thrown by this package.
 *
 * Catching this interface guarantees you handle every image-related
 * failure regardless of whether it originates from an I/O error
 * (ImageException) or a programming mistake (ImageLogicException).
 */
interface ImageExceptionInterface extends \Throwable
{
}
