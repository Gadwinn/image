<?php

declare(strict_types=1);

namespace G\Image\Operation;

use G\Image\Enum\Position;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for cropping a region out of an image.
 *
 * All methods return the same instance for fluent chaining.
 */
interface CroppableInterface
{
    /**
     * Crop a rectangular region from the image.
     *
     * The origin (0, 0) is the top-left corner of the image.
     * If the crop rectangle exceeds the image boundaries, implementations
     * MUST throw {@see InvalidImageArgumentException}.
     *
     * @param int $width  Width of the crop region in pixels (must be positive).
     * @param int $height Height of the crop region in pixels (must be positive).
     * @param int $x      X offset of the top-left corner of the crop region.
     * @param int $y      Y offset of the top-left corner of the crop region.
     * @return static
     * @throws InvalidImageArgumentException When dimensions are not positive or the
     *                                       region extends beyond the image boundaries.
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function crop(int $width, int $height, int $x = 0, int $y = 0): static;

    /**
     * Automatically trim uniform-color borders from the image edges.
     *
     * The reference color is sampled from the pixel at the given $corner.
     * The $threshold controls how much the border pixels may deviate from the
     * reference before they are considered non-border (0 = exact match only).
     *
     * @param int      $threshold Tolerance for color deviation (0–255 per channel).
     * @param Position $corner    Corner pixel used as the reference color sample.
     * @return static
     * @throws InvalidImageArgumentException When $threshold is out of range.
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function trim(int $threshold = 0, Position $corner = Position::TOP_LEFT): static;
}
