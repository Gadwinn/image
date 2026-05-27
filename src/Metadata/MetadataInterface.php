<?php

declare(strict_types=1);

namespace G\Image\Metadata;

use G\Image\Enum\Format;
use G\Image\Exception\UnsupportedOperationException;

/**
 * Contract for reading and writing image metadata.
 *
 * All methods are available after an image has been loaded via
 * {@see \G\Image\Io\InputInterface}. Calling them on an uninitialised
 * driver state is implementation-defined behaviour.
 *
 * Dimension getters ({@see getWidth()}, {@see getHeight()}) are always supported.
 * Embedded-metadata methods (EXIF, IPTC) are format-dependent; when the active
 * driver or the current format does not support them, implementations MUST throw
 * {@see UnsupportedOperationException} rather than returning empty results
 * silently.
 */
interface MetadataInterface
{
    /**
     * Return the image width in pixels.
     *
     * @return int Width in pixels (>= 1).
     */
    public function getWidth(): int;

    /**
     * Return the image height in pixels.
     *
     * @return int Height in pixels (>= 1).
     */
    public function getHeight(): int;

    /**
     * Return the current image format.
     *
     * This reflects the format as last set by a conversion method or as
     * detected on load.
     *
     * @return Format The current image format.
     */
    public function getFormat(): Format;

    /**
     * Return the EXIF metadata embedded in the image.
     *
     * The returned array is keyed by EXIF tag name (e.g. "DateTime",
     * "GPSLatitude"). Values may be scalars, arrays, or null for malformed
     * tags. An empty array is returned when no EXIF block is present.
     *
     * @return array<string, mixed> EXIF data keyed by tag name.
     * @throws UnsupportedOperationException When the driver or current format does not support EXIF.
     */
    public function getExif(): array;

    /**
     * Replace the EXIF block with the given data.
     *
     * Passing an empty array effectively strips the EXIF block.
     * Implementations MUST validate tag names and values where possible.
     *
     * @param array<string, mixed> $data EXIF data keyed by tag name.
     * @return static
     * @throws UnsupportedOperationException When the driver or current format does not support EXIF.
     */
    public function setExif(array $data): static;

    /**
     * Return the IPTC metadata embedded in the image.
     *
     * The returned array is keyed by IPTC field name (e.g. "2#005" or
     * "DocumentTitle"). An empty array is returned when no IPTC block is present.
     *
     * @return array<string, mixed> IPTC data keyed by field name.
     * @throws UnsupportedOperationException When the driver or current format does not support IPTC.
     */
    public function getIptc(): array;

    /**
     * Replace the IPTC block with the given data.
     *
     * Passing an empty array effectively strips the IPTC block.
     *
     * @param array<string, mixed> $data IPTC data keyed by field name.
     * @return static
     * @throws UnsupportedOperationException When the driver or current format does not support IPTC.
     */
    public function setIptc(array $data): static;

    /**
     * Remove all embedded metadata from the image (EXIF, IPTC, XMP, ICC profiles, etc.).
     *
     * Useful before publishing images to avoid leaking sensitive information
     * (GPS coordinates, camera model, author, etc.).
     *
     * @return static
     * @throws UnsupportedOperationException When the driver does not support metadata removal.
     */
    public function stripMetadata(): static;
}
