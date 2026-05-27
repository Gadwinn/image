<?php

declare(strict_types=1);

namespace G\Image\Io;

use G\Image\Exception\ImageLoadException;
use Psr\Http\Message\StreamInterface;

/**
 * Contract for loading image data from various sources into the driver state.
 *
 * Every method populates the internal image state and returns the same instance
 * for fluent chaining. Implementations MUST throw {@see ImageLoadException} on
 * any failure (file not found, unsupported format, corrupt data, etc.).
 *
 * The interface is intentionally narrow: it does not deal with format conversion
 * or image operations — those are defined in dedicated interfaces.
 */
interface InputInterface
{
    /**
     * Load an image from a filesystem path.
     *
     * @param string $path Absolute or relative path to the image file.
     * @return static
     * @throws ImageLoadException When the file does not exist, is not readable,
     *                            or contains unsupported / corrupt image data.
     */
    public function fromFile(string $path): static;

    /**
     * Load an image from a URL (HTTP or HTTPS).
     *
     * Implementations are responsible for fetching the remote resource.
     * Redirects, authentication, and timeouts are implementation-defined.
     *
     * @param string $url A valid HTTP or HTTPS URL pointing to an image.
     * @return static
     * @throws ImageLoadException When the URL is unreachable, the response is
     *                            not a valid image, or the driver rejects the data.
     */
    public function fromUrl(string $url): static;

    /**
     * Load an image from raw binary string data.
     *
     * @param string $data Raw binary image data (e.g. the contents of a JPEG file).
     * @return static
     * @throws ImageLoadException When the data cannot be decoded as a valid image.
     */
    public function fromString(string $data): static;

    /**
     * Load an image from a base64-encoded string (no data-URI prefix).
     *
     * @param string $data Base64-encoded image data.
     * @return static
     * @throws ImageLoadException When the data cannot be decoded as a valid image.
     */
    public function fromBase64(string $data): static;

    /**
     * Load an image from a driver-native binary blob.
     *
     * The expected type of $blob is driver-defined (e.g. a raw binary string
     * returned by {@see \G\Image\Io\OutputInterface::toBlob()},
     * a GdImage, an Imagick object, etc.).
     *
     * @param mixed $blob Driver-native blob containing image data.
     * @return static
     * @throws ImageLoadException When the blob cannot be decoded as a valid image.
     */
    public function fromBlob(mixed $blob): static;

    /**
     * Load an image from an open PHP resource handle.
     *
     * The resource must be a readable stream (e.g. the result of {@see fopen()}).
     * The caller retains ownership; the resource is NOT closed after reading.
     *
     * @param resource $resource A readable PHP stream resource.
     * @return static
     * @throws ImageLoadException When the resource is not readable or does not
     *                            yield valid image data.
     */
    public function fromResource(mixed $resource): static;

    /**
     * Load an image from a PSR-7 {@see StreamInterface}.
     *
     * The stream is read from its current position to the end.
     * The caller retains ownership; the stream is NOT closed after reading.
     *
     * @param StreamInterface $stream A readable PSR-7 stream carrying image data.
     * @return static
     * @throws ImageLoadException When the stream is not readable or does not
     *                            yield valid image data.
     */
    public function fromStream(StreamInterface $stream): static;
}
