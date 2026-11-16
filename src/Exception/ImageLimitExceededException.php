<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when a decoded (or about-to-be-decoded) image exceeds the pixel
 * limit configured via {@see \G\Image\Io\PixelLimitInterface::limitPixels()}.
 *
 * Carries both the measured pixel count and the configured limit so callers
 * can build an informative message without re-parsing the exception text.
 */
final class ImageLimitExceededException extends ImageException
{
    public function __construct(
        public readonly int $pixelCount,
        public readonly int $limit,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf(
                'Image exceeds the configured pixel limit: %d pixels measured, limit is %d pixels.',
                $pixelCount,
                $limit,
            ),
            previous: $previous,
        );
    }
}
