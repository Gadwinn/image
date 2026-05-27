<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Exception\ImageLoadException;

/**
 * Contract for managing the lifecycle and state of a driver instance.
 *
 * These methods give callers control over memory and pipeline branching
 * without depending on language-level object cloning, which is
 * driver-implementation-specific.
 */
interface ImageStateInterface
{
    /**
     * Return whether an image is currently loaded in the driver.
     *
     * Useful as a guard before calling any operation that requires a loaded image.
     *
     * @return bool True when an image has been loaded and is ready for operations.
     */
    public function isLoaded(): bool;

    /**
     * Return a fully independent deep copy of the current driver state.
     *
     * The returned instance shares no internal resource with the original.
     * Operations on the copy do not affect the original and vice-versa.
     * Use this to branch a pipeline (e.g. produce a thumbnail and a full-size
     * version from the same source without reloading).
     *
     * @return static A new driver instance with an identical image state.
     * @throws ImageLoadException When no image is currently loaded.
     */
    public function copy(): static;

    /**
     * Release the underlying driver resource and free associated memory.
     *
     * After calling this method the instance MUST NOT be used for any further
     * operation. Callers should discard the reference immediately.
     * Calling destroy() on an already-destroyed instance is a no-op.
     */
    public function destroy(): void;
}
