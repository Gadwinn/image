<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Base class for all logic (programmatic) image exceptions.
 *
 * Extend this class for failures caused by incorrect API usage:
 * malformed color values, invalid argument combinations, etc.
 * These errors indicate a bug in the calling code and should be
 * caught and fixed at development time.
 *
 * Use {@see ImageException} for runtime / environmental failures.
 */
abstract class ImageLogicException extends \LogicException implements ImageExceptionInterface
{
}
