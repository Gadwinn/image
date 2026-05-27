<?php

declare(strict_types=1);

namespace G\Image\Io;

use G\Image\Exception\ImageSaveException;
use Psr\Http\Message\StreamInterface;

/**
 * Contract for exporting the current image state to various destinations.
 *
 * Output methods operate on the image as it currently stands in the driver
 * (format, quality, and any transformations already applied). They do NOT
 * alter the driver state and return {@see static} for fluent chaining where
 * applicable, or the encoded data directly.
 *
 * Quality and format are set upstream via {@see \G\Image\Operation\ConvertibleInterface}.
 * Output methods simply flush the current state to the requested target.
 *
 * Implementations MUST throw {@see ImageSaveException} on any failure
 * (permission denied, encoding error, unwritable stream, etc.).
 */
interface OutputInterface
{
    /**
     * Write the image to the filesystem at the given path.
     *
     * Parent directories must already exist. If a file already exists at
     * $path it will be overwritten.
     *
     * @param string $path Absolute or relative destination file path.
     * @return static
     * @throws ImageSaveException When the file cannot be created or written.
     */
    public function save(string $path): static;

    /**
     * Encode the image and return it as a raw binary string.
     *
     * @return string Raw binary image data.
     * @throws ImageSaveException When encoding fails.
     */
    public function toString(): string;

    /**
     * Encode the image and return it as a base64 string (no data-URI prefix).
     *
     * @return string Base64-encoded image data.
     * @throws ImageSaveException When encoding fails.
     */
    public function toBase64(): string;

    /**
     * Return the driver-native representation of the encoded image.
     *
     * The exact return type is driver-defined (e.g. a raw binary string from
     * GD, an ImagickBlob, etc.) and MUST be documented by each implementation.
     * The result can be passed back to
     * {@see \G\Image\Io\InputInterface::fromBlob()} on a compatible
     * driver to reload the image without a disk round-trip.
     *
     * @return mixed Driver-native blob representing the encoded image.
     * @throws ImageSaveException When encoding fails.
     */
    public function toBlob(): mixed;

    /**
     * Encode the image and write the result to an open PHP resource handle.
     *
     * The caller retains ownership; the resource is NOT closed after writing.
     *
     * @param resource $resource A writable PHP stream resource.
     * @return static
     * @throws ImageSaveException When encoding fails or the resource is not writable.
     */
    public function toResource(mixed $resource): static;

    /**
     * Encode the image and write the result to a PSR-7 stream.
     *
     * The caller retains ownership of the stream; it is NOT closed after writing.
     *
     * @param StreamInterface $stream A writable PSR-7 stream.
     * @return static
     * @throws ImageSaveException When encoding fails or the stream is not writable.
     */
    public function toStream(StreamInterface $stream): static;
}
