<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Exception\ImageLoadException;
use G\Image\Exception\InvalidImageArgumentException;

/**
 * Argument checks shared by the bundled drivers.
 *
 * @internal
 */
trait ValidatesInput
{
    /** Quality range of the lossy encoders (JPEG, WebP, AVIF, HEIC). */
    private const QUALITY_MIN = 1;
    private const QUALITY_MAX = 100;

    /** Compression range of the PNG encoder. */
    private const PNG_COMPRESSION_MIN = 0;
    private const PNG_COMPRESSION_MAX = 9;

    /** Compression flag of the BMP encoder (0 = none, 1 = RLE). */
    private const BMP_COMPRESSION_MIN = 0;
    private const BMP_COMPRESSION_MAX = 1;

    /** WebP: "RIFF", 4 size bytes, then "WEBP". */
    private const RIFF_SIGNATURE = 'RIFF';
    private const WEBP_SIGNATURE = 'WEBP';
    private const WEBP_OFFSET = 8;

    /** ISO base media file format (AVIF, HEIC): 4 size bytes, then "ftyp". */
    private const FTYP_SIGNATURE = 'ftyp';
    private const FTYP_OFFSET = 4;

    private const BOX_TYPE_LENGTH = 4;

    /**
     * Only remote HTTP(S) images: any other stream wrapper (file://, php://,
     * phar://, data://...) would turn fromUrl() into a local file reader.
     *
     * @throws ImageLoadException When the URL is not an http or https URL.
     */
    private function assertHttpUrl(string $url): void
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new ImageLoadException(sprintf('Only http and https URLs can be fetched, got "%s".', $url));
        }
    }

    /**
     * @throws InvalidImageArgumentException When $quality is outside [$min, $max].
     */
    private function assertQuality(?int $quality, int $min, int $max, string $format): void
    {
        if ($quality !== null && ($quality < $min || $quality > $max)) {
            throw new InvalidImageArgumentException(
                sprintf('%s quality must be between %d and %d, %d given.', $format, $min, $max, $quality)
            );
        }
    }

    private function assertLossyQuality(?int $quality, string $format): void
    {
        $this->assertQuality($quality, self::QUALITY_MIN, self::QUALITY_MAX, $format);
    }

    private function assertPngCompression(?int $quality): void
    {
        $this->assertQuality($quality, self::PNG_COMPRESSION_MIN, self::PNG_COMPRESSION_MAX, 'PNG');
    }

    private function assertBmpCompression(?int $quality): void
    {
        $this->assertQuality($quality, self::BMP_COMPRESSION_MIN, self::BMP_COMPRESSION_MAX, 'BMP');
    }

    /**
     * Refuse data that does not start with the signature of a raster format.
     *
     * A decoder such as ImageMagick picks its coder from the content itself:
     * without this check, an SVG, MVG, MSL or "text:" payload uploaded under
     * an image name would reach coders able to read local files or fetch
     * URLs. Accepted: JPEG, PNG, GIF, WebP, BMP, TIFF and the ISO base media
     * file format ("ftyp" box: AVIF, HEIC).
     *
     * @throws ImageLoadException When the signature is not recognised.
     */
    private function assertRasterSignature(string $data): void
    {
        $signatures = [
            "\xFF\xD8\xFF",        // JPEG
            "\x89PNG\r\n\x1A\n",   // PNG
            'GIF87a',
            'GIF89a',
            'BM',                  // BMP
            "II*\x00",             // TIFF, little-endian
            "MM\x00*",             // TIFF, big-endian
        ];

        foreach ($signatures as $signature) {
            if (str_starts_with($data, $signature)) {
                return;
            }
        }

        $isWebp = str_starts_with($data, self::RIFF_SIGNATURE)
            && substr($data, self::WEBP_OFFSET, self::BOX_TYPE_LENGTH) === self::WEBP_SIGNATURE;

        if ($isWebp || substr($data, self::FTYP_OFFSET, self::BOX_TYPE_LENGTH) === self::FTYP_SIGNATURE) {
            return;
        }

        throw new ImageLoadException(
            'The data is not a supported raster image (JPEG, PNG, GIF, WebP, BMP, TIFF, AVIF or HEIC).'
        );
    }
}
