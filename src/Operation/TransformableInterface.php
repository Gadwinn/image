<?php

declare(strict_types=1);

namespace G\Image\Operation;

use G\Image\Color\Color;
use G\Image\Enum\Flip;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for geometric transformations that do not alter the image content
 * (rotation and mirroring).
 *
 * All methods return the same instance for fluent chaining.
 */
interface TransformableInterface
{
    /**
     * Rotate the image clockwise by the given angle in degrees.
     *
     * For non-multiple-of-90° angles, the canvas is expanded to fit the rotated
     * image. Newly revealed corners are filled with $background (transparent by
     * default for formats that support it, opaque white otherwise).
     *
     * @param float      $angle      Clockwise rotation in degrees (any value; values
     *                               outside [0, 360) are normalized by implementations).
     * @param Color|null $background Fill color for the corners revealed after rotation.
     *                               Null lets the driver choose (transparent or white).
     * @return static
     * @throws InvalidImageArgumentException When $angle is not a finite number.
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function rotate(float $angle, ?Color $background = null): static;

    /**
     * Mirror the image along the given axis.
     *
     * @param Flip $direction The axis of mirroring.
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function flip(Flip $direction): static;
}
