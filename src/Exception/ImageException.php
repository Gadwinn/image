<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Base class for all runtime (environmental) image exceptions.
 *
 * Extend this class for failures that depend on the runtime environment:
 * missing files, inaccessible URLs, unsupported driver capabilities, etc.
 *
 * Use {@see ImageLogicException} for programming mistakes (invalid arguments,
 * malformed values) that should have been caught at development time.
 */
abstract class ImageException extends \RuntimeException implements ImageExceptionInterface
{
}
