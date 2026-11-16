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

    /**
     * Resolve the drawing origin so that this anchor point of a box measuring
     * ($minX, $minY)-($maxX, $maxY) relative to its own natural drawing origin
     * lands exactly at ($anchorX, $anchorY).
     *
     * Unlike {@see self::toOffset()} (which positions an element inside a
     * container), this positions a box around a single target point — the
     * use case for anchored text, where ($anchorX, $anchorY) is supplied by
     * the caller and the box extents come from a font metrics measurement.
     *
     * $minX/$minY/$maxX/$maxY are expressed relative to the box's own natural
     * origin (e.g. (0, 0)-(width, height) for a top-left-origin box such as
     * GD's built-in bitmap fonts, or a font engine's bounding box relative to
     * a baseline origin for TrueType/OpenType text).
     *
     * @param float $anchorX Target X coordinate for this anchor point.
     * @param float $anchorY Target Y coordinate for this anchor point.
     * @param float $minX    Left extent of the box relative to its natural origin.
     * @param float $minY    Top extent of the box relative to its natural origin.
     * @param float $maxX    Right extent of the box relative to its natural origin.
     * @param float $maxY    Bottom extent of the box relative to its natural origin.
     * @return array{0: float, 1: float} [originX, originY] to use as the box's natural drawing origin.
     */
    public function toAnchorOrigin(
        float $anchorX,
        float $anchorY,
        float $minX,
        float $minY,
        float $maxX,
        float $maxY,
    ): array {
        $originX = match ($this) {
            self::TOP_LEFT,
            self::MIDDLE_LEFT,
            self::BOTTOM_LEFT   => $anchorX - $minX,
            self::TOP_RIGHT,
            self::MIDDLE_RIGHT,
            self::BOTTOM_RIGHT  => $anchorX - $maxX,
            default             => $anchorX - ($minX + $maxX) / self::CENTER_DIVISOR,
        };

        $originY = match ($this) {
            self::TOP_LEFT,
            self::TOP_CENTER,
            self::TOP_RIGHT    => $anchorY - $minY,
            self::BOTTOM_LEFT,
            self::BOTTOM_CENTER,
            self::BOTTOM_RIGHT => $anchorY - $maxY,
            default            => $anchorY - ($minY + $maxY) / self::CENTER_DIVISOR,
        };

        return [$originX, $originY];
    }
}
