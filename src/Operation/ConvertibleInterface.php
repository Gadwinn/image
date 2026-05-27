<?php

declare(strict_types=1);

namespace G\Image\Operation;

use G\Image\Enum\Format;
use G\Image\Exception\UnsupportedFormatException;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for converting the image to a specific output format.
 *
 * Methods set the target format (and optionally the encoding quality) on the
 * driver state. The actual encoding happens when an output method from
 * {@see \G\Image\Io\OutputInterface} is called.
 *
 * All methods return the same instance for fluent chaining.
 *
 * Quality semantics per format:
 *   - JPEG / WEBP / AVIF / HEIC : 1 (worst) – 100 (best); null = driver default.
 *   - PNG  : compression level 0 (none) – 9 (max); null = driver default (~6).
 *   - BMP  : 0 = uncompressed, 1 = RLE compressed; null = driver default.
 *   - TIFF : driver-defined (e.g. Imagick compression constants); null = driver default.
 *   - GIF  : no quality or compression setting available.
 *
 * Implementations MUST throw {@see UnsupportedFormatException} when the active
 * driver does not support the requested format, and
 * {@see UnsupportedOperationException} when format conversion is not available.
 */
interface ConvertibleInterface
{
    /**
     * Set the output format to JPEG.
     *
     * @param int|null $quality Encoding quality 1–100, or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support JPEG output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toJpeg(?int $quality = null): static;

    /**
     * Set the output format to PNG.
     *
     * @param int|null $quality Compression level 0 (no compression) – 9 (max), or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support PNG output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toPng(?int $quality = null): static;

    /**
     * Set the output format to WebP.
     *
     * @param int|null $quality Encoding quality 1–100, or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support WebP output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toWebp(?int $quality = null): static;

    /**
     * Set the output format to AVIF.
     *
     * @param int|null $quality Encoding quality 1–100, or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support AVIF output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toAvif(?int $quality = null): static;

    /**
     * Set the output format to GIF.
     *
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support GIF output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toGif(): static;

    /**
     * Set the output format to BMP.
     *
     * @param int|null $quality 0 = uncompressed, 1 = RLE compressed, or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support BMP output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toBmp(?int $quality = null): static;

    /**
     * Set the output format to TIFF.
     *
     * @param int|null $quality Driver-defined compression setting (e.g. Imagick compression constants),
     *                          or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support TIFF output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toTiff(?int $quality = null): static;

    /**
     * Set the output format to HEIC.
     *
     * @param int|null $quality Encoding quality 1–100, or null for driver default.
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support HEIC output.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toHeic(?int $quality = null): static;

    /**
     * Set the output format using a {@see Format} enum case.
     *
     * This is the generic alternative to the named shortcut methods above.
     *
     * @param Format   $format  The target output format.
     * @param int|null $quality Encoding parameter; semantics depend on the format (see class PHPDoc).
     * @return static
     * @throws UnsupportedFormatException    When the driver does not support the given format.
     * @throws UnsupportedOperationException When the driver does not support format conversion.
     */
    public function toFormat(Format $format, ?int $quality = null): static;
}
