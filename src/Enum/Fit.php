<?php

declare(strict_types=1);

namespace G\Image\Enum;

/**
 * Defines how an image is resized to fit a target bounding box.
 *
 * - CONTAIN: preserves aspect ratio, the entire image fits inside the box,
 *            leaving potential empty space on two sides.
 * - COVER:   preserves aspect ratio, the box is fully covered by the image,
 *            the excess is cropped according to the chosen {@see Position}.
 * - FILL:    stretches the image to exactly fill the box, ignoring aspect ratio.
 */
enum Fit: string
{
    case CONTAIN = 'contain';
    case COVER   = 'cover';
    case FILL    = 'fill';
}
