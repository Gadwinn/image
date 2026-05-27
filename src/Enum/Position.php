<?php

declare(strict_types=1);

namespace G\Image\Enum;

/**
 * Anchor point used to position or align an element inside a container.
 *
 * Used by resize (COVER crop anchor), crop, watermark, text placement, etc.
 * Provides {@see self::toOffset()} to compute concrete pixel coordinates
 * so that each driver does not duplicate the same arithmetic.
 */
enum Position: string
{
    case TOP_LEFT      = 'top_left';
    case TOP_CENTER    = 'top_center';
    case TOP_RIGHT     = 'top_right';
    case MIDDLE_LEFT   = 'middle_left';
    case MIDDLE_CENTER = 'middle_center';
    case MIDDLE_RIGHT  = 'middle_right';
    case BOTTOM_LEFT   = 'bottom_left';
    case BOTTOM_CENTER = 'bottom_center';
    case BOTTOM_RIGHT  = 'bottom_right';

    /** Divisor used to compute centered offsets. */
    private const CENTER_DIVISOR = 2;

    /**
     * Compute the top-left pixel offset [x, y] at which an element of
     * ($elementWidth × $elementHeight) should be placed inside a container
     * of ($containerWidth × $containerHeight) to honour this anchor.
     *
     * @param int $containerWidth  Width of the outer container in pixels
     * @param int $containerHeight Height of the outer container in pixels
     * @param int $elementWidth    Width of the element to position in pixels
     * @param int $elementHeight   Height of the element to position in pixels
     * @return array{0: int, 1: int} [x, y] in pixels (may be negative when element is larger)
     */
    public function toOffset(
        int $containerWidth,
        int $containerHeight,
        int $elementWidth,
        int $elementHeight,
    ): array {
        $x = match ($this) {
            self::TOP_LEFT,
            self::MIDDLE_LEFT,
            self::BOTTOM_LEFT   => 0,
            self::TOP_RIGHT,
            self::MIDDLE_RIGHT,
            self::BOTTOM_RIGHT  => $containerWidth - $elementWidth,
            default             => (int) (($containerWidth - $elementWidth) / self::CENTER_DIVISOR),
        };

        $y = match ($this) {
            self::TOP_LEFT,
            self::TOP_CENTER,
            self::TOP_RIGHT    => 0,
            self::BOTTOM_LEFT,
            self::BOTTOM_CENTER,
            self::BOTTOM_RIGHT => $containerHeight - $elementHeight,
            default            => (int) (($containerHeight - $elementHeight) / self::CENTER_DIVISOR),
        };

        return [$x, $y];
    }
}
