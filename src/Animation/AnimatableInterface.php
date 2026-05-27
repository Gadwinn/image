<?php

declare(strict_types=1);

namespace G\Image\Animation;

use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for reading and manipulating animated images (GIF, WEBP, AVIF, etc.).
 *
 * A "frame" is the driver-native representation of a single animation step.
 * Its exact type is driver-defined and MUST be documented by each implementation.
 *
 * Frames are indexed from 0. All index-based methods MUST throw
 * {@see InvalidImageArgumentException} when an out-of-bounds index is given.
 *
 * All methods that modify the frame list return the same instance for fluent
 * chaining. Implementations MUST throw {@see UnsupportedOperationException}
 * when the active driver or current format does not support animation.
 */
interface AnimatableInterface
{
    /**
     * Return the total number of frames in the animation.
     *
     * Returns 1 for static (non-animated) images.
     *
     * @return int Number of frames (>= 1).
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function getFrameCount(): int;

    /**
     * Return all frames as an ordered list of driver-native frame objects.
     *
     * The exact type of each element is driver-defined and MUST be documented
     * by each implementation.
     *
     * @return list<mixed> Ordered list of driver-native frame representations.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function getFrames(): array;

    /**
     * Return the driver-native representation of a single frame.
     *
     * @param int $index Zero-based frame index.
     * @return mixed Driver-native frame representation.
     * @throws InvalidImageArgumentException When $index is out of bounds.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function getFrame(int $index): mixed;

    /**
     * Insert a new frame into the animation.
     *
     * When $index is null the frame is appended after the last existing frame.
     * When $index is 0 the frame is prepended before all existing frames.
     * When $index is n the frame is inserted at position n; the frame currently
     * at n and all subsequent frames shift forward by one.
     *
     * @param mixed    $frame Driver-native frame representation.
     * @param int      $delay Display duration of the frame in milliseconds (must be >= 0).
     * @param int|null $index Zero-based insertion position, or null to append.
     * @return static
     * @throws InvalidImageArgumentException When $delay is negative, $index is out of bounds,
     *                                       or $frame is invalid.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function addFrame(mixed $frame, int $delay, ?int $index = null): static;

    /**
     * Remove the frame at the given index.
     *
     * Subsequent frames shift down to fill the gap.
     *
     * @param int $index Zero-based frame index.
     * @return static
     * @throws InvalidImageArgumentException When $index is out of bounds.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function removeFrame(int $index): static;

    /**
     * Return the display duration of a frame in milliseconds.
     *
     * @param int $index Zero-based frame index.
     * @return int Delay in milliseconds (>= 0).
     * @throws InvalidImageArgumentException When $index is out of bounds.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function getFrameDelay(int $index): int;

    /**
     * Set the display duration of a frame in milliseconds.
     *
     * @param int $index Zero-based frame index.
     * @param int $delay Display duration in milliseconds (must be >= 0).
     * @return static
     * @throws InvalidImageArgumentException When $index is out of bounds or $delay is negative.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function setFrameDelay(int $index, int $delay): static;

    /**
     * Return the number of times the animation loops.
     *
     * 0 means infinite loop, any positive value is the exact repeat count.
     *
     * @return int Loop count (>= 0).
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function getLoopCount(): int;

    /**
     * Set the number of times the animation loops.
     *
     * @param int $count Loop count (>= 0); 0 means infinite.
     * @return static
     * @throws InvalidImageArgumentException When $count is negative.
     * @throws UnsupportedOperationException When the driver does not support animation.
     */
    public function setLoopCount(int $count): static;
}
