<?php

declare(strict_types=1);

namespace G\Image;

/**
 * Marker interface representing a loaded image object.
 *
 * Any object that can serve as an image source in compositing operations
 * (e.g. as a watermark overlay) must implement this interface.
 *
 * The full contract is defined by the driver interface, which extends this
 * interface together with all I/O and operation contracts.
 *
 * Driver implementations are encouraged to check whether the received image
 * originates from the same driver and optimize accordingly (e.g. direct
 * in-memory compositing). When drivers differ they SHOULD fall back to a
 * binary interchange (encode the source to a raw string, then decode it).
 */
interface ImageInterface
{
}
