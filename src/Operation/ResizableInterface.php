<?php

declare(strict_types=1);

namespace G\Image\Operation;

use G\Image\Color\Color;
use G\Image\Enum\Fit;
use G\Image\Enum\Position;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for resizing an image to new dimensions.
 *
 * All methods return the same instance for fluent chaining.
 * At least one of $width or $height must be non-null; passing both as null
 * MUST throw {@see InvalidImageArgumentException}.
 * When only one dimension is provided, implementations MUST scale
 * the other proportionally to preserve the aspect ratio.
 */
interface ResizableInterface
{
    /**
     * Resize the image to fit within the given dimensions using the specified fit mode.
     *
     * @param int|null   $width      Target width in pixels, or null to infer from height.
     * @param int|null   $height     Target height in pixels, or null to infer from width.
     * @param Fit        $fit        How the image is scaled when the aspect ratio differs.
     * @param Position   $position   Anchor used when cropping (relevant for {@see Fit::COVER}).
     * @param Color|null $background Fill color for letterbox areas ({@see Fit::CONTAIN}).
     *                               Null means transparent (or driver default for opaque formats).
     * @param bool       $upscale    When false, the image is never enlarged beyond its original
     *                               dimensions even if the target is larger.
     * @return static
     * @throws InvalidImageArgumentException When both $width and $height are null,
     *                                       or when a dimension is not a positive integer.
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function resize(
        ?int $width,
        ?int $height,
        Fit $fit = Fit::CONTAIN,
        Position $position = Position::MIDDLE_CENTER,
        ?Color $background = null,
        bool $upscale = true,
    ): static;

    /**
     * Scale the image proportionally by a percentage of its current size.
     *
     * A $percentage of 100 is a no-op, 50 halves both dimensions, 200 doubles them.
     *
     * @param int $percentage Scaling factor as a percentage (must be > 0).
     * @return static
     * @throws InvalidImageArgumentException When $percentage is not a positive integer.
     * @throws UnsupportedOperationException When the active driver does not support this operation.
     */
    public function scale(int $percentage): static;
}
