<?php

declare(strict_types=1);

namespace G\Image\Operation;

use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for pixel-level image filters and adjustments.
 *
 * All methods return the same instance for fluent chaining.
 * Filter methods that accept an intensity parameter expect a positive integer;
 * adjustment methods that accept a level expect a value in the range
 * documented per method.
 *
 * Every method MUST throw {@see UnsupportedOperationException} when the
 * active driver does not implement it.
 */
interface FilterableInterface
{
    /**
     * Apply a blur effect to the image.
     *
     * @param int $amount Blur intensity (must be > 0). Driver-defined scale.
     * @return static
     * @throws InvalidImageArgumentException When $amount is not a positive integer.
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function blur(int $amount = 1): static;

    /**
     * Apply a sharpening effect to the image.
     *
     * @param int $amount Sharpening intensity (must be > 0). Driver-defined scale.
     * @return static
     * @throws InvalidImageArgumentException When $amount is not a positive integer.
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function sharpen(int $amount = 1): static;

    /**
     * Adjust the brightness of the image.
     *
     * @param int $level Brightness adjustment from -100 (darkest) to +100 (brightest).
     *                   0 is a no-op.
     * @return static
     * @throws InvalidImageArgumentException When $level is outside [-100, 100].
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function brightness(int $level): static;

    /**
     * Adjust the contrast of the image.
     *
     * @param int $level Contrast adjustment from -100 (flat) to +100 (maximum contrast).
     *                   0 is a no-op.
     * @return static
     * @throws InvalidImageArgumentException When $level is outside [-100, 100].
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function contrast(int $level): static;

    /**
     * Convert the image to grayscale.
     *
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function grayscale(): static;

    /**
     * Invert all pixel colors (negative effect).
     *
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function negate(): static;

    /**
     * Apply a warm sepia tone to the image.
     *
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function sepia(): static;

    /**
     * Apply an emboss effect to the image.
     *
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support this filter.
     */
    public function emboss(): static;
}
