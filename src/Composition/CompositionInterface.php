<?php

declare(strict_types=1);

namespace G\Image\Composition;

use G\Image\Color\Color;
use G\Image\Enum\Position;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\ImageInterface;

/**
 * Contract for compositing and drawing operations layered on top of an image.
 *
 * All methods return the same instance for fluent chaining.
 * Coordinates use the image's top-left corner as origin (0, 0).
 *
 * Every method MUST throw {@see UnsupportedOperationException} when the active
 * driver does not implement it.
 */
interface CompositionInterface
{
    /**
     * Opacity value representing a fully opaque layer (100 %).
     * Used as the default for {@see watermark()}.
     */
    public const OPACITY_FULL = 100;

    /**
     * Opacity value representing a fully transparent layer (0 %).
     */
    public const OPACITY_NONE = 0;

    /**
     * Overlay a watermark image on top of the current image.
     *
     * The $watermark can be any driver instance that implements
     * {@see ImageInterface} — an image loaded from a file, a URL, a stream,
     * a generated canvas, etc. This leaves the source completely open to the
     * caller without restricting it to a file path.
     *
     * Implementations SHOULD optimise compositing when both images share the
     * same driver (e.g. direct in-memory blending). When they differ they MUST
     * fall back to a binary interchange (encode + reload).
     *
     * @param ImageInterface $watermark The image to overlay.
     * @param Position       $position  Where to place the watermark on the canvas.
     * @param int            $opacity   Watermark opacity from 0 (invisible) to 100 (fully opaque).
     * @return static
     * @throws InvalidImageArgumentException When $opacity is outside [0, 100].
     * @throws UnsupportedOperationException When the active driver does not support compositing.
     */
    public function watermark(
        ImageInterface $watermark,
        Position $position = Position::BOTTOM_RIGHT,
        int $opacity = self::OPACITY_FULL,
    ): static;

    /**
     * Render a text string onto the image.
     *
     * $content MUST be UTF-8 in every code path, regardless of driver or font.
     *
     * $x/$y designate the position of the $anchor point of the rendered text's
     * bounding box, not a baseline origin. For example, with the default
     * `Position::TOP_LEFT`, ($x, $y) is the top-left corner of the text box —
     * matching how {@see self::rect()} already interprets its own $x/$y, so
     * that neighbouring drawing primitives share one coordinate convention.
     *
     * When $fontPath is null, the driver's built-in font is used instead of a
     * TrueType/OpenType file — this lets simple text rendering work without
     * shipping a font file. Built-in font support is driver-specific; probe
     * it via {@see \G\Image\Driver\CapabilityInterface::supportsOperation()}
     * with the operation key `'text.builtinFont'`. Drivers that do not
     * support a built-in font throw {@see UnsupportedOperationException} when
     * $fontPath is null. Drivers that do support one MAY have restrictions —
     * documented on the implementation — such as a fixed set of sizes, a
     * limited character range, or no rotation support.
     *
     * @param string   $content  The text to render, UTF-8 encoded.
     * @param int      $x        X coordinate of the $anchor point of the text box.
     * @param int      $y        Y coordinate of the $anchor point of the text box.
     * @param ?string  $fontPath Absolute path to a TrueType (.ttf) or OpenType (.otf)
     *                           font file, or null to use the driver's built-in font.
     * @param int      $fontSize Font size in points (must be positive).
     * @param Color    $color    Text color.
     * @param float    $angle    Clockwise rotation angle of the text in degrees.
     * @param Position $anchor   Which point of the text's bounding box ($x, $y) designates.
     * @return static
     * @throws ImageLoadException            When the font file cannot be read.
     * @throws InvalidImageArgumentException When $fontSize is not positive, when $content
     *                                        contains a character the active font cannot
     *                                        represent, or when $angle is unsupported by
     *                                        the active font (see implementation docs).
     * @throws UnsupportedOperationException When the active driver does not support text
     *                                        rendering, or does not support a built-in font
     *                                        and $fontPath is null.
     */
    public function text(
        string $content,
        int $x,
        int $y,
        ?string $fontPath,
        int $fontSize,
        Color $color,
        float $angle = 0.0,
        Position $anchor = Position::TOP_LEFT,
    ): static;

    /**
     * Draw a rectangle on the image.
     *
     * @param int        $x      X coordinate of the top-left corner.
     * @param int        $y      Y coordinate of the top-left corner.
     * @param int        $width  Width of the rectangle in pixels (must be positive).
     * @param int        $height Height of the rectangle in pixels (must be positive).
     * @param Color      $stroke Border color. Pass a transparent color to skip the border.
     * @param Color|null $fill   Fill color, or null for no fill (outline only).
     * @return static
     * @throws InvalidImageArgumentException When $width or $height are not positive.
     * @throws UnsupportedOperationException When the active driver does not support drawing.
     */
    public function rect(
        int $x,
        int $y,
        int $width,
        int $height,
        Color $stroke,
        ?Color $fill = null,
    ): static;

    /**
     * Draw an ellipse (or circle) on the image.
     *
     * The center of the ellipse is at ($cx, $cy).
     *
     * @param int        $cx     X coordinate of the center.
     * @param int        $cy     Y coordinate of the center.
     * @param int        $width  Horizontal diameter in pixels (must be positive).
     * @param int        $height Vertical diameter in pixels (must be positive).
     * @param Color      $stroke Border color. Pass a transparent color to skip the border.
     * @param Color|null $fill   Fill color, or null for no fill (outline only).
     * @return static
     * @throws InvalidImageArgumentException When $width or $height are not positive.
     * @throws UnsupportedOperationException When the active driver does not support drawing.
     */
    public function ellipse(
        int $cx,
        int $cy,
        int $width,
        int $height,
        Color $stroke,
        ?Color $fill = null,
    ): static;

    /**
     * Draw a straight line between two points.
     *
     * @param int   $x1    X coordinate of the start point.
     * @param int   $y1    Y coordinate of the start point.
     * @param int   $x2    X coordinate of the end point.
     * @param int   $y2    Y coordinate of the end point.
     * @param Color $color Line color.
     * @return static
     * @throws UnsupportedOperationException When the active driver does not support drawing.
     */
    public function line(
        int $x1,
        int $y1,
        int $x2,
        int $y2,
        Color $color
    ): static;

    /**
     * Draw an arc on the image.
     *
     * @param int        $cx         X coordinate of the center.
     * @param int        $cy         Y coordinate of the center.
     * @param int        $width      Horizontal diameter of the bounding ellipse (must be positive).
     * @param int        $height     Vertical diameter of the bounding ellipse (must be positive).
     * @param int        $startAngle Start angle in degrees (0 = 3 o'clock, clockwise).
     * @param int        $endAngle   End angle in degrees.
     * @param Color      $stroke     Arc color.
     * @param Color|null $fill       Fill color for the pie-slice area, or null for no fill.
     * @return static
     * @throws InvalidImageArgumentException When $width or $height are not positive.
     * @throws UnsupportedOperationException When the active driver does not support drawing.
     */
    public function arc(
        int $cx,
        int $cy,
        int $width,
        int $height,
        int $startAngle,
        int $endAngle,
        Color $stroke,
        ?Color $fill = null,
    ): static;
}
