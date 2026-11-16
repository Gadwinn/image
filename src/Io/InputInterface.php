<?php

declare(strict_types=1);

namespace G\Image\Io;

use G\Image\Color\Color;
use G\Image\Exception\ImageLimitExceededException;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\InvalidImageArgumentException;
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
     * Create a new blank (empty) canvas instead of loading existing data.
     *
     * This is the only {@see InputInterface} method that does not read from
     * an external source — it exists so that drawing operations exposed by
     * {@see \G\Image\Composition\CompositionInterface} (rect, ellipse, text,
     * etc.) are reachable without first loading an unrelated image.
     *
     * A pixel limit configured via {@see PixelLimitInterface::limitPixels()}
     * applies here too: $width × $height is checked directly, with no header
     * to inspect since the caller supplies the dimensions.
     *
     * @param int        $width      Canvas width in pixels (must be positive).
     * @param int        $height     Canvas height in pixels (must be positive).
     * @param Color|null $background Fill color. Null means fully transparent
     *                               where the driver supports an alpha channel,
     *                               or opaque white when it does not.
     * @return static
     * @throws InvalidImageArgumentException When $width or $height is not a positive integer.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and $width × $height exceeds it.
     */
    public function fromBlank(int $width, int $height, ?Color $background = null): static;

    /**
     * Load an image from a filesystem path.
     *
     * @param string $path Absolute or relative path to the image file.
     * @return static
     * @throws ImageLoadException When the file does not exist, is not readable,
     *                            or contains unsupported / corrupt image data.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the file's header
     *         announces dimensions that exceed it.
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
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the downloaded data's
     *         header announces dimensions that exceed it.
     */
    public function fromUrl(string $url): static;

    /**
     * Load an image from raw binary string data.
     *
     * @param string $data Raw binary image data (e.g. the contents of a JPEG file).
     * @return static
     * @throws ImageLoadException When the data cannot be decoded as a valid image.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the data's header
     *         announces dimensions that exceed it.
     */
    public function fromString(string $data): static;

    /**
     * Load an image from a base64-encoded string (no data-URI prefix).
     *
     * @param string $data Base64-encoded image data.
     * @return static
     * @throws ImageLoadException When the data cannot be decoded as a valid image.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the decoded data's
     *         header announces dimensions that exceed it.
     */
    public function fromBase64(string $data): static;

    /**
     * Load an image from a driver-native binary blob.
     *
     * The expected type of $blob is driver-defined (e.g. a raw binary string
     * returned by {@see \G\Image\Io\OutputInterface::toBlob()},
     * a GdImage, an Imagick object, etc.).
     *
     * When a pixel limit is configured via
     * {@see \G\Image\Io\PixelLimitInterface::limitPixels()} and $blob is
     * already a driver-native decoded object, the limit is checked against
     * its existing dimensions rather than a header — the object was already
     * fully decoded by the caller before reaching this method, so the check
     * cannot prevent the memory allocation that already happened, only
     * refuse to proceed with an object that violates the configured guard.
     *
     * @param mixed $blob Driver-native blob containing image data.
     * @return static
     * @throws ImageLoadException When the blob cannot be decoded as a valid image.
     * @throws ImageLimitExceededException When a pixel limit is
     *         configured and the (already-decoded) image exceeds it.
     */
    public function fromBlob(mixed $blob): static;

    /**
     * Load an image from an open PHP resource handle.
     *
     * The resource must be a readable stream (e.g. the result of {@see fopen()}).
     * The caller retains ownership; the resource is NOT closed after reading.
     * The resource is read fully into memory before the pixel-limit header
     * check runs, same as {@see fromString()}.
     *
     * @param resource $resource A readable PHP stream resource.
     * @return static
     * @throws ImageLoadException When the resource is not readable or does not
     *                            yield valid image data.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the buffered data's
     *         header announces dimensions that exceed it.
     */
    public function fromResource(mixed $resource): static;

    /**
     * Load an image from a PSR-7 {@see StreamInterface}.
     *
     * The stream is read from its current position to the end.
     * The caller retains ownership; the stream is NOT closed after reading.
     * The stream is read fully into memory before the pixel-limit header
     * check runs, same as {@see fromString()}.
     *
     * @param StreamInterface $stream A readable PSR-7 stream carrying image data.
     * @return static
     * @throws ImageLoadException When the stream is not readable or does not
     *                            yield valid image data.
     * @throws ImageLimitExceededException When a pixel limit is configured via
     *         {@see PixelLimitInterface::limitPixels()} and the buffered data's
     *         header announces dimensions that exceed it.
     */
    public function fromStream(StreamInterface $stream): static;
}
