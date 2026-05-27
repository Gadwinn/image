<?php

declare(strict_types=1);

namespace G\Image\Enum;

use G\Image\Exception\UnsupportedFormatException;

/**
 * Canonical list of image formats supported by this library.
 *
 * Each case's backed value is the official MIME type, which means
 * {@see self::from()} and {@see self::tryFrom()} work directly with MIME strings.
 *
 * Driver implementations use {@see self::supportsTransparency()},
 * {@see self::supportsAnimation()}, and {@see self::isLossy()} to make
 * encoding decisions without hardcoding format-specific logic.
 */
enum Format: string
{
    case JPEG = 'image/jpeg';
    case PNG  = 'image/png';
    case WEBP = 'image/webp';
    case AVIF = 'image/avif';
    case GIF  = 'image/gif';
    case BMP  = 'image/bmp';
    case TIFF = 'image/tiff';
    case HEIC = 'image/heic';
    case ICO  = 'image/vnd.microsoft.icon';
    case WBMP = 'image/vnd.wap.wbmp';
    case XBM  = 'image/xbm';
    case JP2  = 'image/jp2';

    // -------------------------------------------------------------------------
    // Extension → Format map (multiple extensions may map to the same format)
    // -------------------------------------------------------------------------
    private const EXTENSION_MAP = [
        'jpg'  => self::JPEG,
        'jpeg' => self::JPEG,
        'png'  => self::PNG,
        'webp' => self::WEBP,
        'avif' => self::AVIF,
        'gif'  => self::GIF,
        'bmp'  => self::BMP,
        'tiff' => self::TIFF,
        'tif'  => self::TIFF,
        'heic' => self::HEIC,
        'heif' => self::HEIC,
        'ico'  => self::ICO,
        'wbmp' => self::WBMP,
        'xbm'  => self::XBM,
        'jp2'  => self::JP2,
        'jpx'  => self::JP2,
        'jpf'  => self::JP2,
        'j2k'  => self::JP2,
    ];

    // -------------------------------------------------------------------------
    // Factories
    // -------------------------------------------------------------------------

    /**
     * Create a Format from a MIME type string.
     *
     * Equivalent to {@see self::from()} but throws {@see UnsupportedFormatException}
     * (package-specific) instead of PHP's built-in \ValueError.
     *
     * @param string $mime e.g. 'image/jpeg'
     * @return self
     * @throws UnsupportedFormatException When the MIME type is not recognized
     */
    public static function fromMimeType(string $mime): self
    {
        $format = self::tryFrom(strtolower(trim($mime)));

        if ($format === null) {
            throw new UnsupportedFormatException(
                sprintf('Unsupported MIME type: "%s".', $mime)
            );
        }

        return $format;
    }

    /**
     * Create a Format from a file extension (case-insensitive, leading dot optional).
     *
     * @param string $extension e.g. 'jpg', '.JPG', 'jpeg'
     * @return self
     * @throws UnsupportedFormatException When the extension is not recognized
     */
    public static function fromExtension(string $extension): self
    {
        $format = self::tryFromExtension($extension);

        if ($format === null) {
            throw new UnsupportedFormatException(
                sprintf('Unsupported file extension: "%s".', $extension)
            );
        }

        return $format;
    }

    /**
     * Try to create a Format from a file extension. Returns null on failure.
     *
     * @param string $extension e.g. 'jpg', '.JPG', 'jpeg'
     * @return self|null
     */
    public static function tryFromExtension(string $extension): ?self
    {
        $normalized = strtolower(ltrim(trim($extension), '.'));
        return self::EXTENSION_MAP[$normalized] ?? null;
    }

    // -------------------------------------------------------------------------
    // Descriptive helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the primary (most common) file extension for this format, without a leading dot.
     *
     * @return string e.g. 'jpg' for JPEG
     */
    public function toExtension(): string
    {
        return match ($this) {
            self::JPEG => 'jpg',
            self::PNG  => 'png',
            self::WEBP => 'webp',
            self::AVIF => 'avif',
            self::GIF  => 'gif',
            self::BMP  => 'bmp',
            self::TIFF => 'tiff',
            self::HEIC => 'heic',
            self::ICO  => 'ico',
            self::WBMP => 'wbmp',
            self::XBM  => 'xbm',
            self::JP2  => 'jp2',
        };
    }

    /**
     * Returns a human-readable label for this format.
     *
     * @return string e.g. 'JPEG', 'WebP', 'JPEG 2000'
     */
    public function label(): string
    {
        return match ($this) {
            self::JPEG => 'JPEG',
            self::PNG  => 'PNG',
            self::WEBP => 'WebP',
            self::AVIF => 'AVIF',
            self::GIF  => 'GIF',
            self::BMP  => 'Bitmap',
            self::TIFF => 'TIFF',
            self::HEIC => 'HEIC',
            self::ICO  => 'ICO',
            self::WBMP => 'WBMP',
            self::XBM  => 'XBM',
            self::JP2  => 'JPEG 2000',
        };
    }

    // -------------------------------------------------------------------------
    // Capability flags
    // -------------------------------------------------------------------------

    /**
     * Returns true when this format supports an alpha (transparency) channel.
     *
     * Note: GIF supports 1-bit (on/off) transparency only.
     *
     * @return bool
     */
    public function supportsTransparency(): bool
    {
        return match ($this) {
            self::PNG,
            self::WEBP,
            self::AVIF,
            self::GIF,
            self::TIFF,
            self::HEIC,
            self::ICO  => true,
            default    => false,
        };
    }

    /**
     * Returns true when this format can store multiple frames (animation).
     *
     * Note: TIFF multi-page and animated AVIF (AVIS) are included.
     *
     * @return bool
     */
    public function supportsAnimation(): bool
    {
        return match ($this) {
            self::GIF,
            self::WEBP,
            self::AVIF,
            self::TIFF => true,
            default    => false,
        };
    }

    /**
     * Returns true when this format's default encoding is lossy.
     *
     * Formats that support both modes (WebP, AVIF, JP2) return true because
     * their default encoding pipeline is lossy.
     *
     * @return bool
     */
    public function isLossy(): bool
    {
        return match ($this) {
            self::JPEG,
            self::WEBP,
            self::AVIF,
            self::GIF,
            self::HEIC,
            self::JP2  => true,
            default    => false,
        };
    }
}
