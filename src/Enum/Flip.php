<?php

declare(strict_types=1);

namespace G\Image\Enum;

/**
 * Axis along which an image is mirrored.
 *
 * Used by {@see \G\Image\Operation\TransformableInterface::flip()}.
 */
enum Flip: string
{
    /** Mirror left ↔ right. */
    case HORIZONTAL = 'horizontal';

    /** Mirror top ↔ bottom. */
    case VERTICAL = 'vertical';

    /** Mirror both axes simultaneously. */
    case BOTH = 'both';
}
