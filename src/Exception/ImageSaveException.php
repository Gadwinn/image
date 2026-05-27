<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when an image cannot be saved or serialized to its output destination.
 *
 * Common causes:
 *   - target directory does not exist or is not writable
 *   - encoding or compression failed internally
 *   - no image has been loaded before calling a save/output method
 */
final class ImageSaveException extends ImageException
{
}
