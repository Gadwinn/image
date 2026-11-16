<?php

declare(strict_types=1);

namespace G\Image\Io;

use G\Image\Exception\InvalidImageArgumentException;

/**
 * Contract for an opt-in guard against decoding excessively large images.
 *
 * Off by default: the library never imposes a hardcoded ceiling, since
 * legitimately huge images must keep working for consumers that need them.
 * A caller that accepts untrusted input (e.g. public uploads) can opt in
 * explicitly before loading:
 *
 * ```php
 * Image::create(GDDriver::class)->limitPixels(200_000_000)->fromFile($upload);
 * ```
 *
 * Implementations MUST enforce the limit by inspecting the image header
 * (dimensions) rather than by decoding the full image first — checking after
 * decoding guards nothing, since the memory has already been allocated by
 * the time the check would run.
 */
interface PixelLimitInterface
{
    /**
     * Set (or clear) the maximum number of pixels (width × height) an input
     * image may have once decoded.
     *
     * This method only records the limit; it never inspects or loads an
     * image itself, so it never throws {@see \G\Image\Exception\ImageLimitExceededException}.
     * That exception is thrown by the `from*()` method called afterwards —
     * see {@see \G\Image\Io\InputInterface} for which ones enforce it.
     *
     * Must be called before the relevant `from*()` method to take effect.
     *
     * @param int|null $maxPixels Maximum pixel count, or null to disable the guard (default).
     * @return static
     * @throws InvalidImageArgumentException When $maxPixels is zero or negative.
     */
    public function limitPixels(?int $maxPixels): static;
}
