<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Color\Color;
use G\Image\Enum\Fit;
use G\Image\Enum\Flip;
use G\Image\Enum\Format;
use G\Image\Enum\Position;
use G\Image\Exception\ImageLimitExceededException;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\ImageSaveException;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedFormatException;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\ImageInterface;
use G\Image\Io\OutputInterface;
use Imagick;
use ImagickDraw;
use ImagickException;
use ImagickPixel;
use Psr\Http\Message\StreamInterface;

/**
 * Imagick-based image driver.
 *
 * Implements the full {@see DriverInterface} contract using PHP's Imagick extension
 * (ext-imagick). Requires PHP >= 8.2 with ext-imagick enabled.
 *
 * Supported formats: JPEG, PNG, GIF, WebP, AVIF, TIFF, HEIC, BMP (availability
 * depends on the ImageMagick build).
 *
 * Animation: getFrameCount() and getFrames() return the actual frame data for
 * multi-frame images. Mutation methods (addFrame, removeFrame, setFrameDelay,
 * setLoopCount) throw {@see UnsupportedOperationException}.
 *
 * EXIF: readable on load for JPEG; writing throws {@see UnsupportedOperationException}.
 */
final class ImagickDriver implements DriverInterface, DriverAvailabilityInterface
{
    use CapturesNativeWarnings;

    // ─── Default encoding quality per format ───────────────────────────────────
    private const DEFAULT_QUALITY_JPEG = 85;
    private const DEFAULT_QUALITY_WEBP = 80;
    private const DEFAULT_QUALITY_AVIF = 75;
    private const DEFAULT_QUALITY_PNG  = 6;

    // ─── Filter level range ────────────────────────────────────────────────────
    private const LEVEL_MIN = -100;
    private const LEVEL_MAX =  100;

    // ─── Blur / sharpen parameters ─────────────────────────────────────────────
    private const BLUR_RADIUS        = 2.0;
    private const BLUR_SIGMA         = 0.8;
    private const SHARPEN_SIGMA      = 1.0;
    private const SHARPEN_AMOUNT     = 1.0;
    private const SHARPEN_THRESHOLD  = 0.05;

    // ─── Emboss parameters ────────────────────────────────────────────────────
    private const EMBOSS_RADIUS = 0.0;
    private const EMBOSS_SIGMA  = 1.0;

    // ─── Sepia tone threshold (fraction of quantum range) ─────────────────────
    private const SEPIA_THRESHOLD_RATIO = 0.8;

    // ─── Modulate "neutral" value (100 = no change) ───────────────────────────
    private const MODULATE_NEUTRAL = 100;

    // ─── Scale / dimension helpers ─────────────────────────────────────────────
    private const PERCENTAGE_DIVISOR = 100;
    private const CENTER_DIVISOR     =   2;

    // ─── Color channel max / geometry helpers ──────────────────────────────────
    private const CHANNEL_MAX       = 255;
    private const HALF_CIRCLE_DEG   = 180;
    private const FULL_CIRCLE_DEG   = 360.0;
    private const STROKE_WIDTH_THIN = 1.0;

    // ─── HTTP fetch timeout (seconds) ─────────────────────────────────────────
    private const HTTP_TIMEOUT = 10;

    // ─── Imagick resize filter blur param ─────────────────────────────────────
    private const IMAGICK_FILTER_BLUR = 1;

    // ─── Operations supported by this driver ──────────────────────────────────
    private const SUPPORTED_OPERATIONS = [
        'fromBlank',
        'resize', 'scale',
        'crop', 'trim',
        'rotate', 'flip',
        'blur', 'sharpen', 'brightness', 'contrast', 'grayscale', 'negate', 'sepia', 'emboss',
        'toJpeg', 'toPng', 'toWebp', 'toAvif', 'toGif', 'toBmp', 'toTiff', 'toHeic', 'toFormat',
        'watermark', 'text', 'rect', 'ellipse', 'line', 'arc',
        'getWidth', 'getHeight', 'getFormat', 'getExif', 'getIptc', 'stripMetadata',
        'getFrameCount', 'getFrames', 'getFrame', 'getFrameDelay', 'getLoopCount',
        'isLoaded', 'copy', 'destroy',
        'supportsFormat', 'supportsOperation', 'getSupportedFormats',
    ];

    // ─── State ─────────────────────────────────────────────────────────────────
    private ?Imagick $imagick = null;

    private Format $format = Format::JPEG;

    private ?int $quality = null;

    private ?int $maxPixels = null;

    /** @var array<string, mixed> */
    private array $exif = [];

    /** @var array<string, mixed> */
    private array $iptc = [];

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @throws UnsupportedOperationException When ext-imagick is not loaded.
     */
    public function __construct()
    {
        if (!extension_loaded('imagick')) {
            throw new UnsupportedOperationException(
                'The Imagick extension (ext-imagick) is required to use ImagickDriver.'
            );
        }
    }

    public static function isAvailable(): bool
    {
        return extension_loaded('imagick');
    }

    // =========================================================================
    // PixelLimitInterface
    // =========================================================================

    public function limitPixels(?int $maxPixels): static
    {
        if ($maxPixels !== null && $maxPixels <= 0) {
            throw new InvalidImageArgumentException(
                'Pixel limit must be a positive integer, or null to disable it.'
            );
        }

        $this->maxPixels = $maxPixels;

        return $this;
    }

    // =========================================================================
    // InputInterface
    // =========================================================================

    public function fromBlank(int $width, int $height, ?Color $background = null): static
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Canvas dimensions must be positive, got %d×%d.', $width, $height)
            );
        }

        $this->assertWithinPixelLimit($width, $height);

        try {
            $imagick = new Imagick();
            $imagick->newImage($width, $height, $this->toImagickPixel($background ?? Color::transparent()), 'png');
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to allocate a blank canvas.', previous: $e);
        }

        $this->imagick = $imagick;
        $this->format  = Format::PNG;
        $this->exif    = [];
        $this->iptc    = [];

        return $this;
    }

    public function fromFile(string $path): static
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageLoadException(
                sprintf('File not found or not readable: "%s".', $path)
            );
        }

        if ($this->maxPixels !== null) {
            [$info] = $this->captureWarning(static fn () => getimagesize($path));
            if (is_array($info)) {
                $this->assertWithinPixelLimit((int) $info[0], (int) $info[1]);
            }
        }

        try {
            $imagick = new Imagick();
            $imagick->pingImage($path);
            $this->assertWithinPixelLimit($imagick->getImageWidth(), $imagick->getImageHeight());
            $imagick->readImage($path);
            $imagick->setIteratorIndex(0);
        } catch (ImagickException $e) {
            throw new ImageLoadException(
                sprintf('Imagick could not read file: "%s".', $path),
                previous: $e,
            );
        }

        $this->imagick = $imagick;
        $this->format  = $this->detectFormatFromImagick();
        $this->exif    = $this->readExif();
        $this->iptc    = [];

        return $this;
    }

    public function fromUrl(string $url): static
    {
        $context = stream_context_create([
            'http'  => ['timeout' => self::HTTP_TIMEOUT, 'method' => 'GET'],
            'https' => ['timeout' => self::HTTP_TIMEOUT, 'method' => 'GET'],
        ]);

        [$data, $warning] = $this->captureWarning(
            static fn () => file_get_contents($url, false, $context)
        );
        if ($data === false) {
            throw new ImageLoadException(
                sprintf(
                    'Failed to fetch image from URL: "%s".%s',
                    $url,
                    $warning !== null ? ' ' . $warning : '',
                )
            );
        }

        return $this->fromString($data);
    }

    public function fromString(string $data): static
    {
        $this->assertPixelLimitFromHeader($data);

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($data);
            $imagick->setIteratorIndex(0);
        } catch (ImagickException $e) {
            throw new ImageLoadException(
                'Imagick could not decode the provided image data.',
                previous: $e,
            );
        }

        $this->imagick = $imagick;
        $this->format  = $this->detectFormatFromImagick();
        $this->exif    = $this->readExif();
        $this->iptc    = [];

        return $this;
    }

    public function fromBase64(string $data): static
    {
        $decoded = base64_decode($data, strict: true);
        if ($decoded === false) {
            throw new ImageLoadException('Failed to decode base64 image data.');
        }

        return $this->fromString($decoded);
    }

    public function fromBlob(mixed $blob): static
    {
        if ($blob instanceof Imagick) {
            // Already decoded: only the existing dimensions can be checked, the
            // memory allocation (if any) already happened in the caller's code.
            $this->assertWithinPixelLimit($blob->getImageWidth(), $blob->getImageHeight());

            $this->imagick = clone $blob;
            $this->imagick->setIteratorIndex(0);
            $this->format = $this->detectFormatFromImagick();
            $this->exif   = $this->readExif();
            $this->iptc   = [];

            return $this;
        }

        if (is_string($blob)) {
            return $this->fromString($blob);
        }

        throw new ImageLoadException(
            'ImagickDriver::fromBlob() expects an Imagick instance or a raw binary string.'
        );
    }

    public function fromResource(mixed $resource): static
    {
        if (!is_resource($resource)) {
            throw new ImageLoadException(
                'ImagickDriver::fromResource() expects a valid PHP stream resource.'
            );
        }

        $data = stream_get_contents($resource);
        if ($data === false) {
            throw new ImageLoadException('Failed to read image data from resource.');
        }

        return $this->fromString($data);
    }

    public function fromStream(StreamInterface $stream): static
    {
        $data = (string) $stream;
        if ($data === '') {
            throw new ImageLoadException('Stream is empty or could not be read.');
        }

        return $this->fromString($data);
    }

    // =========================================================================
    // OutputInterface
    // =========================================================================

    public function save(string $path): static
    {
        $data = $this->toString();
        [$result, $warning] = $this->captureWarning(static fn () => file_put_contents($path, $data));
        if ($result === false) {
            throw new ImageSaveException(
                sprintf(
                    'Failed to write image to: "%s".%s',
                    $path,
                    $warning !== null ? ' ' . $warning : '',
                )
            );
        }

        return $this;
    }

    public function toString(): string
    {
        return $this->encode($this->requireImage(), $this->format, $this->quality);
    }

    public function toBase64(): string
    {
        return base64_encode($this->toString());
    }

    /**
     * Returns the underlying {@see Imagick} instance.
     *
     * Can be passed back to {@see fromBlob()} on another ImagickDriver instance
     * to share the pixel data without a re-encode/decode round-trip.
     */
    public function toBlob(): mixed
    {
        return $this->requireImage();
    }

    public function toResource(mixed $resource): static
    {
        if (!is_resource($resource)) {
            throw new ImageSaveException(
                'ImagickDriver::toResource() expects a valid PHP stream resource.'
            );
        }

        $data = $this->toString();
        [$result, $warning] = $this->captureWarning(static fn () => fwrite($resource, $data));
        if ($result === false) {
            throw new ImageSaveException(
                'Failed to write image data to the resource.' . ($warning !== null ? ' ' . $warning : '')
            );
        }

        return $this;
    }

    public function toStream(StreamInterface $stream): static
    {
        $stream->write($this->toString());

        return $this;
    }

    // =========================================================================
    // ResizableInterface
    // =========================================================================

    public function resize(
        ?int $width,
        ?int $height,
        Fit $fit = Fit::CONTAIN,
        Position $position = Position::MIDDLE_CENTER,
        ?Color $background = null,
        bool $upscale = true,
    ): static {
        if ($width === null && $height === null) {
            throw new InvalidImageArgumentException(
                'At least one of $width or $height must be non-null.'
            );
        }

        if ($width !== null && $width <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Width must be a positive integer, %d given.', $width)
            );
        }

        if ($height !== null && $height <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Height must be a positive integer, %d given.', $height)
            );
        }

        $image = $this->requireImage();
        $srcW  = $image->getImageWidth();
        $srcH  = $image->getImageHeight();

        if ($width === null) {
            /** @var int<1, max> $height */
            $width = (int) round($srcW * $height / $srcH);
        } elseif ($height === null) {
            $height = (int) round($srcH * $width / $srcW);
        }

        if ($fit === Fit::FILL) {
            $this->resizeFill($width, $height, $upscale);
        } elseif ($fit === Fit::COVER) {
            $this->resizeCover($width, $height, $position, $upscale);
        } else {
            $this->resizeContain($width, $height, $background, $upscale);
        }

        return $this;
    }

    public function scale(int $percentage): static
    {
        if ($percentage <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Scale percentage must be a positive integer, %d given.', $percentage)
            );
        }

        $image = $this->requireImage();
        $newW  = (int) round($image->getImageWidth() * $percentage / self::PERCENTAGE_DIVISOR);
        $newH  = (int) round($image->getImageHeight() * $percentage / self::PERCENTAGE_DIVISOR);

        if ($newW <= 0 || $newH <= 0) {
            throw new InvalidImageArgumentException(
                'Resulting dimensions after scale are too small (less than 1 pixel).'
            );
        }

        try {
            $image->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to scale the image.', previous: $e);
        }

        return $this;
    }

    // =========================================================================
    // CroppableInterface
    // =========================================================================

    public function crop(int $width, int $height, int $x = 0, int $y = 0): static
    {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidImageArgumentException(
                'Crop dimensions must be positive integers.'
            );
        }

        $image = $this->requireImage();
        $srcW  = $image->getImageWidth();
        $srcH  = $image->getImageHeight();

        if ($x < 0 || $y < 0 || $x + $width > $srcW || $y + $height > $srcH) {
            throw new InvalidImageArgumentException(
                'Crop region extends beyond the image boundaries.'
            );
        }

        try {
            $image->cropImage($width, $height, $x, $y);
            $image->setImagePage($width, $height, 0, 0);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to crop the image.', previous: $e);
        }

        return $this;
    }

    public function trim(int $threshold = 0, Position $corner = Position::TOP_LEFT): static
    {
        if ($threshold < 0 || $threshold > self::CHANNEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Trim threshold must be between 0 and %d.', self::CHANNEL_MAX)
            );
        }

        $image = $this->requireImage();
        $imgW  = $image->getImageWidth();
        $imgH  = $image->getImageHeight();

        [$refX, $refY] = $corner->toOffset($imgW, $imgH, 1, 1);

        try {
            $refPixel = $image->getImagePixelColor($refX, $refY);
            $image->setImageBackgroundColor($refPixel);

            $quantum = Imagick::getQuantumRange();
            $fuzz    = $threshold / self::CHANNEL_MAX * $quantum['quantumRangeLong'];

            $image->trimImage($fuzz);
            $image->setImagePage($image->getImageWidth(), $image->getImageHeight(), 0, 0);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to trim the image.', previous: $e);
        }

        return $this;
    }

    // =========================================================================
    // TransformableInterface
    // =========================================================================

    public function rotate(float $angle, ?Color $background = null): static
    {
        if (!is_finite($angle)) {
            throw new InvalidImageArgumentException(
                'Rotation angle must be a finite number.'
            );
        }

        $bg = $background ?? Color::transparent();

        try {
            // Imagick rotates clockwise — matches our API (no negation needed)
            $this->requireImage()->rotateImage($this->toImagickPixel($bg), $angle);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to rotate the image.', previous: $e);
        }

        return $this;
    }

    public function flip(Flip $direction): static
    {
        $image = $this->requireImage();

        try {
            if ($direction === Flip::HORIZONTAL || $direction === Flip::BOTH) {
                // flopImage = horizontal flip (mirror left-right)
                $image->flopImage();
            }

            if ($direction === Flip::VERTICAL || $direction === Flip::BOTH) {
                // flipImage = vertical flip (mirror top-bottom)
                $image->flipImage();
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to flip the image.', previous: $e);
        }

        return $this;
    }

    // =========================================================================
    // FilterableInterface
    // =========================================================================

    public function blur(int $amount = 1): static
    {
        if ($amount <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Blur amount must be a positive integer, %d given.', $amount)
            );
        }

        $image = $this->requireImage();

        try {
            for ($i = 0; $i < $amount; $i++) {
                $image->gaussianBlurImage(self::BLUR_RADIUS, self::BLUR_SIGMA);
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply blur.', previous: $e);
        }

        return $this;
    }

    public function sharpen(int $amount = 1): static
    {
        if ($amount <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Sharpen amount must be a positive integer, %d given.', $amount)
            );
        }

        $image = $this->requireImage();

        try {
            for ($i = 0; $i < $amount; $i++) {
                // radius=0 lets Imagick auto-calculate; sigma controls the spread
                $image->unsharpMaskImage(0.0, self::SHARPEN_SIGMA, self::SHARPEN_AMOUNT, self::SHARPEN_THRESHOLD);
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply sharpening.', previous: $e);
        }

        return $this;
    }

    public function brightness(int $level): static
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Brightness level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX)
            );
        }

        // modulateImage uses 100 as neutral; 100+level maps -100..100 to 0..200
        $modulateBrightness = (float) (self::MODULATE_NEUTRAL + $level);

        try {
            $this->requireImage()->modulateImage(
                $modulateBrightness,
                (float) self::MODULATE_NEUTRAL,
                (float) self::MODULATE_NEUTRAL,
            );
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to adjust brightness.', previous: $e);
        }

        return $this;
    }

    public function contrast(int $level): static
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Contrast level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX)
            );
        }

        try {
            // brightnessContrastImage: positive = more contrast, matching our API
            $this->requireImage()->brightnessContrastImage(0.0, (float) $level);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to adjust contrast.', previous: $e);
        }

        return $this;
    }

    public function grayscale(): static
    {
        try {
            $image = $this->requireImage();
            $image->transformImageColorspace(Imagick::COLORSPACE_GRAY);
            // Convert back to sRGB to maintain broad format compatibility
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply grayscale.', previous: $e);
        }

        return $this;
    }

    public function negate(): static
    {
        try {
            $this->requireImage()->negateImage(false);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to negate the image.', previous: $e);
        }

        return $this;
    }

    public function sepia(): static
    {
        try {
            $quantum = Imagick::getQuantumRange();
            $threshold = self::SEPIA_THRESHOLD_RATIO * $quantum['quantumRangeLong'];
            $this->requireImage()->sepiaToneImage($threshold);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply sepia tone.', previous: $e);
        }

        return $this;
    }

    public function emboss(): static
    {
        try {
            $this->requireImage()->embossImage(self::EMBOSS_RADIUS, self::EMBOSS_SIGMA);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply emboss.', previous: $e);
        }

        return $this;
    }

    // =========================================================================
    // ConvertibleInterface
    // =========================================================================

    public function toJpeg(?int $quality = null): static
    {
        $this->format  = Format::JPEG;
        $this->quality = $quality;

        return $this;
    }

    public function toPng(?int $quality = null): static
    {
        $this->format  = Format::PNG;
        $this->quality = $quality;

        return $this;
    }

    public function toWebp(?int $quality = null): static
    {
        if (empty(Imagick::queryFormats('WEBP'))) {
            throw new UnsupportedFormatException(
                'WebP output is not supported by this Imagick build.'
            );
        }

        $this->format  = Format::WEBP;
        $this->quality = $quality;

        return $this;
    }

    public function toAvif(?int $quality = null): static
    {
        if (empty(Imagick::queryFormats('AVIF'))) {
            throw new UnsupportedFormatException(
                'AVIF output is not supported by this Imagick build.'
            );
        }

        $this->format  = Format::AVIF;
        $this->quality = $quality;

        return $this;
    }

    public function toGif(): static
    {
        $this->format  = Format::GIF;
        $this->quality = null;

        return $this;
    }

    public function toBmp(?int $quality = null): static
    {
        if (empty(Imagick::queryFormats('BMP'))) {
            throw new UnsupportedFormatException(
                'BMP output is not supported by this Imagick build.'
            );
        }

        $this->format  = Format::BMP;
        $this->quality = $quality;

        return $this;
    }

    public function toTiff(?int $quality = null): static
    {
        if (empty(Imagick::queryFormats('TIFF'))) {
            throw new UnsupportedFormatException(
                'TIFF output is not supported by this Imagick build.'
            );
        }

        $this->format  = Format::TIFF;
        $this->quality = $quality;

        return $this;
    }

    public function toHeic(?int $quality = null): static
    {
        if (empty(Imagick::queryFormats('HEIC'))) {
            throw new UnsupportedFormatException(
                'HEIC output is not supported by this Imagick build.'
            );
        }

        $this->format  = Format::HEIC;
        $this->quality = $quality;

        return $this;
    }

    public function toFormat(Format $format, ?int $quality = null): static
    {
        return match ($format) {
            Format::JPEG => $this->toJpeg($quality),
            Format::PNG  => $this->toPng($quality),
            Format::WEBP => $this->toWebp($quality),
            Format::AVIF => $this->toAvif($quality),
            Format::GIF  => $this->toGif(),
            Format::BMP  => $this->toBmp($quality),
            Format::TIFF => $this->toTiff($quality),
            Format::HEIC => $this->toHeic($quality),
            default      => throw new UnsupportedFormatException(
                sprintf('Format "%s" is not supported by the Imagick driver.', $format->value)
            ),
        };
    }

    // =========================================================================
    // CompositionInterface
    // =========================================================================

    public function watermark(
        ImageInterface $watermark,
        Position $position = Position::BOTTOM_RIGHT,
        int $opacity = self::OPACITY_FULL,
    ): static {
        if ($opacity < self::OPACITY_NONE || $opacity > self::OPACITY_FULL) {
            throw new InvalidImageArgumentException(
                sprintf('Opacity must be between %d and %d.', self::OPACITY_NONE, self::OPACITY_FULL)
            );
        }

        $dst = $this->requireImage();

        // Same-driver fast path: share the Imagick pixel data directly
        if ($watermark instanceof self && $watermark->imagick !== null) {
            $wmImagick = clone $watermark->imagick;
        } elseif ($watermark instanceof OutputInterface) {
            try {
                $wmImagick = new Imagick();
                $wmImagick->readImageBlob($watermark->toString());
            } catch (ImagickException $e) {
                throw new ImageLoadException('Failed to load watermark image.', previous: $e);
            }
        } else {
            throw new UnsupportedOperationException(
                'The watermark must be an ImagickDriver instance or implement OutputInterface.'
            );
        }

        try {
            // Ensure the watermark has an alpha channel before adjusting opacity
            if (!$wmImagick->getImageAlphaChannel()) {
                $wmImagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_SET);
            }

            if ($opacity < self::OPACITY_FULL) {
                $wmImagick->evaluateImage(
                    Imagick::EVALUATE_MULTIPLY,
                    $opacity / self::PERCENTAGE_DIVISOR,
                    Imagick::CHANNEL_ALPHA,
                );
            }

            $wmW  = $wmImagick->getImageWidth();
            $wmH  = $wmImagick->getImageHeight();
            $dstW = $dst->getImageWidth();
            $dstH = $dst->getImageHeight();

            [$x, $y] = $position->toOffset($dstW, $dstH, $wmW, $wmH);

            $dst->compositeImage($wmImagick, Imagick::COMPOSITE_OVER, $x, $y);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to composite the watermark.', previous: $e);
        }

        return $this;
    }

    public function text(
        string $content,
        int $x,
        int $y,
        ?string $fontPath,
        int $fontSize,
        Color $color,
        float $angle = 0.0,
        Position $anchor = Position::TOP_LEFT,
    ): static {
        if ($fontSize <= 0) {
            throw new InvalidImageArgumentException(
                'Font size must be a positive integer.'
            );
        }

        if ($fontPath === null) {
            throw new UnsupportedOperationException(
                'ImagickDriver does not support a built-in font; a TrueType/OpenType $fontPath is required.'
            );
        }

        if (!is_file($fontPath) || !is_readable($fontPath)) {
            throw new ImageLoadException(
                sprintf('Font file not found or not readable: "%s".', $fontPath)
            );
        }

        try {
            $draw = new ImagickDraw();
            $draw->setFont($fontPath);
            $draw->setFontSize((float) $fontSize);
            $draw->setFillColor($this->toImagickPixel($color));
            $draw->setTextAntialias(true);

            $image   = $this->requireImage();
            $metrics = $image->queryFontMetrics($draw, $content);
            $width   = (float) $metrics['textWidth'];
            $height  = (float) $metrics['textHeight'];
            $ascender = (float) $metrics['ascender'];

            // queryFontMetrics gives a top-left-origin box; annotateImage still
            // expects a baseline Y, so the ascender re-derives it after anchoring.
            [$originX, $topY] = $anchor->toAnchorOrigin((float) $x, (float) $y, 0.0, 0.0, $width, $height);
            $baselineY = $topY + $ascender;

            // annotateImage angle: clockwise degrees, matching our API
            $image->annotateImage($draw, $originX, $baselineY, $angle, $content);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to render text.', previous: $e);
        }

        return $this;
    }

    public function rect(
        int $x,
        int $y,
        int $width,
        int $height,
        Color $stroke,
        ?Color $fill = null,
    ): static {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidImageArgumentException(
                'Rectangle dimensions must be positive integers.'
            );
        }

        $image = $this->requireImage();
        $x2    = $x + $width - 1;
        $y2    = $y + $height - 1;

        try {
            if ($fill !== null) {
                $draw = new ImagickDraw();
                $draw->setFillColor($this->toImagickPixel($fill));
                $draw->setStrokeWidth(0.0);
                $draw->setStrokeColor(new ImagickPixel('none'));
                $draw->rectangle((float) $x, (float) $y, (float) $x2, (float) $y2);
                $image->drawImage($draw);
            }

            if (!$stroke->isTransparent()) {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('none'));
                $draw->setStrokeColor($this->toImagickPixel($stroke));
                $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
                $draw->rectangle((float) $x, (float) $y, (float) $x2, (float) $y2);
                $image->drawImage($draw);
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to draw rectangle.', previous: $e);
        }

        return $this;
    }

    public function ellipse(
        int $cx,
        int $cy,
        int $width,
        int $height,
        Color $stroke,
        ?Color $fill = null,
    ): static {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidImageArgumentException(
                'Ellipse dimensions must be positive integers.'
            );
        }

        $image = $this->requireImage();
        $rx    = $width  / self::CENTER_DIVISOR;
        $ry    = $height / self::CENTER_DIVISOR;

        try {
            if ($fill !== null) {
                $draw = new ImagickDraw();
                $draw->setFillColor($this->toImagickPixel($fill));
                $draw->setStrokeWidth(0.0);
                $draw->setStrokeColor(new ImagickPixel('none'));
                $draw->ellipse((float) $cx, (float) $cy, $rx, $ry, 0.0, self::FULL_CIRCLE_DEG);
                $image->drawImage($draw);
            }

            if (!$stroke->isTransparent()) {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('none'));
                $draw->setStrokeColor($this->toImagickPixel($stroke));
                $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
                $draw->ellipse((float) $cx, (float) $cy, $rx, $ry, 0.0, self::FULL_CIRCLE_DEG);
                $image->drawImage($draw);
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to draw ellipse.', previous: $e);
        }

        return $this;
    }

    public function line(int $x1, int $y1, int $x2, int $y2, Color $color): static
    {
        try {
            $draw = new ImagickDraw();
            $draw->setStrokeColor($this->toImagickPixel($color));
            $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
            $draw->setFillColor(new ImagickPixel('none'));
            $draw->line((float) $x1, (float) $y1, (float) $x2, (float) $y2);
            $this->requireImage()->drawImage($draw);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to draw line.', previous: $e);
        }

        return $this;
    }

    public function arc(
        int $cx,
        int $cy,
        int $width,
        int $height,
        int $startAngle,
        int $endAngle,
        Color $stroke,
        ?Color $fill = null,
    ): static {
        if ($width <= 0 || $height <= 0) {
            throw new InvalidImageArgumentException(
                'Arc bounding dimensions must be positive integers.'
            );
        }

        $image = $this->requireImage();
        $rx    = $width  / self::CENTER_DIVISOR;
        $ry    = $height / self::CENTER_DIVISOR;

        try {
            if ($fill !== null) {
                $startRad    = deg2rad($startAngle);
                $endRad      = deg2rad($endAngle);
                $largeArc    = abs($endAngle - $startAngle) > self::HALF_CIRCLE_DEG;
                $endX        = $cx + $rx * cos($endRad);
                $endY        = $cy + $ry * sin($endRad);

                $draw = new ImagickDraw();
                $draw->setFillColor($this->toImagickPixel($fill));
                $draw->setStrokeWidth(0.0);
                $draw->setStrokeColor(new ImagickPixel('none'));
                $draw->pathStart();
                $draw->pathMoveToAbsolute((float) $cx, (float) $cy);
                $draw->pathLineToAbsolute(
                    $cx + $rx * cos($startRad),
                    $cy + $ry * sin($startRad),
                );
                $draw->pathEllipticArcAbsolute($rx, $ry, 0.0, $largeArc, true, $endX, $endY);
                $draw->pathClose();
                $draw->pathFinish();
                $image->drawImage($draw);
            }

            if (!$stroke->isTransparent()) {
                $bx1  = (float) ($cx - $width / self::CENTER_DIVISOR);
                $by1  = (float) ($cy - $height / self::CENTER_DIVISOR);
                $bx2  = (float) ($cx + $width / self::CENTER_DIVISOR);
                $by2  = (float) ($cy + $height / self::CENTER_DIVISOR);
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('none'));
                $draw->setStrokeColor($this->toImagickPixel($stroke));
                $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
                $draw->arc($bx1, $by1, $bx2, $by2, (float) $startAngle, (float) $endAngle);
                $image->drawImage($draw);
            }
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to draw arc.', previous: $e);
        }

        return $this;
    }

    // =========================================================================
    // MetadataInterface
    // =========================================================================

    public function getWidth(): int
    {
        return $this->requireImage()->getImageWidth();
    }

    public function getHeight(): int
    {
        return $this->requireImage()->getImageHeight();
    }

    public function getFormat(): Format
    {
        return $this->format;
    }

    public function getExif(): array
    {
        if ($this->format !== Format::JPEG) {
            throw new UnsupportedOperationException(
                sprintf(
                    'EXIF metadata is only available for JPEG; current format is "%s".',
                    $this->format->value,
                )
            );
        }

        return $this->exif;
    }

    public function setExif(array $data): static
    {
        throw new UnsupportedOperationException('Imagick does not support writing EXIF metadata.');
    }

    public function getIptc(): array
    {
        if ($this->format !== Format::JPEG) {
            throw new UnsupportedOperationException(
                sprintf(
                    'IPTC metadata is only available for JPEG; current format is "%s".',
                    $this->format->value,
                )
            );
        }

        return $this->iptc;
    }

    public function setIptc(array $data): static
    {
        throw new UnsupportedOperationException('Imagick does not support writing IPTC metadata.');
    }

    public function stripMetadata(): static
    {
        $this->exif = [];
        $this->iptc = [];

        return $this;
    }

    // =========================================================================
    // AnimatableInterface
    // =========================================================================

    /**
     * Returns the actual number of frames (e.g. for animated GIFs); 0 when no image is loaded.
     */
    public function getFrameCount(): int
    {
        return $this->imagick !== null ? $this->imagick->getNumberImages() : 0;
    }

    /**
     * Returns a list of Imagick instances, one per frame.
     *
     * @return list<Imagick>
     */
    public function getFrames(): array
    {
        if ($this->imagick === null) {
            return [];
        }

        $frames = [];
        $count  = $this->imagick->getNumberImages();

        for ($i = 0; $i < $count; $i++) {
            $this->imagick->setIteratorIndex($i);
            $frames[] = $this->imagick->getImage();
        }

        // Reset iterator to first frame
        $this->imagick->setIteratorIndex(0);

        return $frames;
    }

    public function getFrame(int $index): mixed
    {
        $count = $this->getFrameCount();

        if (!$this->isLoaded() || $index < 0 || $index >= $count) {
            throw new InvalidImageArgumentException(
                sprintf('Frame index %d is out of bounds (ImagickDriver has %d frame(s)).', $index, $count)
            );
        }

        /** @var Imagick $imagick */
        $imagick = $this->imagick;
        $imagick->setIteratorIndex($index);
        $frame = $imagick->getImage();
        $imagick->setIteratorIndex(0);

        return $frame;
    }

    public function addFrame(mixed $frame, int $delay, ?int $index = null): static
    {
        throw new UnsupportedOperationException(
            'ImagickDriver does not support adding animation frames in this version.'
        );
    }

    public function removeFrame(int $index): static
    {
        throw new UnsupportedOperationException(
            'ImagickDriver does not support removing animation frames in this version.'
        );
    }

    public function getFrameDelay(int $index): int
    {
        $count = $this->getFrameCount();

        if (!$this->isLoaded() || $index < 0 || $index >= $count) {
            throw new InvalidImageArgumentException(
                sprintf('Frame index %d is out of bounds (ImagickDriver has %d frame(s)).', $index, $count)
            );
        }

        /** @var Imagick $imagick */
        $imagick = $this->imagick;
        $imagick->setIteratorIndex($index);
        $delay = $imagick->getImageDelay();
        $imagick->setIteratorIndex(0);

        return $delay;
    }

    public function setFrameDelay(int $index, int $delay): static
    {
        throw new UnsupportedOperationException(
            'ImagickDriver does not support setting frame delays in this version.'
        );
    }

    public function getLoopCount(): int
    {
        return 0;
    }

    public function setLoopCount(int $count): static
    {
        throw new UnsupportedOperationException(
            'ImagickDriver does not support setting animation loop count in this version.'
        );
    }

    // =========================================================================
    // CapabilityInterface
    // =========================================================================

    public function supportsFormat(Format $format): bool
    {
        return in_array($format, $this->getSupportedFormats(), strict: true);
    }

    public function supportsOperation(string $operation): bool
    {
        return in_array($operation, self::SUPPORTED_OPERATIONS, strict: true);
    }

    /** @return list<Format> */
    public function getSupportedFormats(): array
    {
        /** @var list<Format>|null $cached */
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        $available = (new Imagick())->queryFormats();
        $check     = [
            'JPEG' => Format::JPEG,
            'PNG'  => Format::PNG,
            'WEBP' => Format::WEBP,
            'AVIF' => Format::AVIF,
            'GIF'  => Format::GIF,
            'BMP'  => Format::BMP,
            'TIFF' => Format::TIFF,
            'HEIC' => Format::HEIC,
        ];

        $cached = [];
        foreach ($check as $imagickFmt => $format) {
            if (in_array($imagickFmt, $available, strict: true)) {
                $cached[] = $format;
            }
        }

        return $cached;
    }

    // =========================================================================
    // ImageStateInterface
    // =========================================================================

    public function isLoaded(): bool
    {
        return $this->imagick !== null;
    }

    public function copy(): static
    {
        $clone          = new static();
        $clone->imagick = clone $this->requireImage();
        $clone->format  = $this->format;
        $clone->quality = $this->quality;
        $clone->exif    = $this->exif;
        $clone->iptc    = $this->iptc;

        return $clone;
    }

    public function destroy(): void
    {
        if ($this->imagick !== null) {
            $this->imagick->clear();
        }

        $this->imagick = null;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Return the loaded Imagick instance, or throw if no image has been loaded.
     *
     * @throws ImageLoadException When no image is currently loaded.
     */
    private function requireImage(): Imagick
    {
        if ($this->imagick === null) {
            throw new ImageLoadException(
                'No image is loaded. Call a from*() method before applying operations.'
            );
        }

        return $this->imagick;
    }

    /**
     * Throw when the configured pixel limit is exceeded.
     *
     * No-op when no limit is configured (default).
     *
     * @throws ImageLimitExceededException When the limit is configured and exceeded.
     */
    private function assertWithinPixelLimit(int $width, int $height): void
    {
        if ($this->maxPixels === null) {
            return;
        }

        $pixelCount = $width * $height;
        if ($pixelCount > $this->maxPixels) {
            throw new ImageLimitExceededException($pixelCount, $this->maxPixels);
        }
    }

    /**
     * Enforce the pixel limit from the header of encoded image data, without decoding it.
     *
     * Uses {@see Imagick::pingImageBlob()}, which reads only the header.
     * Silently returns when the header cannot be parsed: the subsequent
     * decode call is responsible for surfacing that failure with a clear message.
     *
     * @throws ImageLimitExceededException When a pixel limit is configured and exceeded.
     */
    private function assertPixelLimitFromHeader(string $data): void
    {
        if ($this->maxPixels === null) {
            return;
        }

        try {
            $probe = new Imagick();
            $probe->pingImageBlob($data);
            $this->assertWithinPixelLimit($probe->getImageWidth(), $probe->getImageHeight());
        } catch (ImagickException) {
            // Header could not be parsed; let the subsequent decode call report it.
        }
    }

    /**
     * Detect the image format from the currently loaded Imagick instance.
     *
     * @throws ImageLoadException When the format cannot be determined or is unsupported.
     */
    private function detectFormatFromImagick(): Format
    {
        $fmt = strtoupper($this->requireImage()->getImageFormat());

        foreach (Format::cases() as $candidate) {
            if ($this->formatToImagickString($candidate) === $fmt) {
                return $candidate;
            }
        }

        throw new ImageLoadException(sprintf('Unsupported image format: "%s".', $fmt));
    }

    /**
     * Read EXIF properties from the loaded Imagick instance (JPEG only).
     *
     * Returns an empty array for non-JPEG formats or when no properties are found.
     *
     * @return array<string, mixed>
     */
    private function readExif(): array
    {
        if ($this->imagick === null || $this->format !== Format::JPEG) {
            return [];
        }

        try {
            /** @var array<string, string> $raw */
            $raw    = $this->imagick->getImageProperties('exif:*');
            $result = [];

            foreach ($raw as $key => $value) {
                // Strip the 'exif:' prefix for API consistency with GDDriver
                $result[str_replace('exif:', '', $key)] = $value;
            }

            return $result;
        } catch (ImagickException) {
            return [];
        }
    }

    /**
     * Encode an Imagick image to a binary string in the given format and quality.
     *
     * @throws ImageSaveException When Imagick encoding fails.
     */
    private function encode(Imagick $image, Format $format, ?int $quality): string
    {
        try {
            $clone = clone $image;
            $clone->setImageFormat($this->formatToImagickString($format));
            $clone->setImageCompressionQuality($quality ?? $this->defaultQuality($format));

            return $clone->getImageBlob();
        } catch (ImagickException $e) {
            throw new ImageSaveException(
                sprintf('Imagick failed to encode the image as %s.', $format->value),
                previous: $e,
            );
        }
    }

    /**
     * Map a {@see Format} to the Imagick format string used by setImageFormat().
     *
     * @throws UnsupportedFormatException When the format is not supported.
     */
    private function formatToImagickString(Format $format): string
    {
        return match ($format) {
            Format::JPEG => 'JPEG',
            Format::PNG  => 'PNG',
            Format::WEBP => 'WEBP',
            Format::AVIF => 'AVIF',
            Format::GIF  => 'GIF',
            Format::BMP  => 'BMP',
            Format::TIFF => 'TIFF',
            Format::HEIC => 'HEIC',
            default      => throw new UnsupportedFormatException(
                sprintf('Format "%s" is not supported by the Imagick driver.', $format->value)
            ),
        };
    }

    /**
     * Return the default encoding quality for the given format.
     */
    private function defaultQuality(Format $format): int
    {
        return match ($format) {
            Format::JPEG => self::DEFAULT_QUALITY_JPEG,
            Format::WEBP => self::DEFAULT_QUALITY_WEBP,
            Format::AVIF => self::DEFAULT_QUALITY_AVIF,
            Format::PNG  => self::DEFAULT_QUALITY_PNG,
            default      => self::DEFAULT_QUALITY_JPEG,
        };
    }

    /**
     * Build an {@see ImagickPixel} from a {@see Color} value object.
     */
    private function toImagickPixel(Color $color): ImagickPixel
    {
        return new ImagickPixel(
            sprintf('rgba(%d,%d,%d,%.4f)', $color->red, $color->green, $color->blue, $color->alpha)
        );
    }

    // ─── Resize helpers ───────────────────────────────────────────────────────

    /**
     * Resize to exact dimensions, optionally preventing upscaling.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeFill(int $dstW, int $dstH, bool $upscale): void
    {
        $image = $this->requireImage();

        if (!$upscale) {
            $dstW = min($dstW, $image->getImageWidth());
            $dstH = min($dstH, $image->getImageHeight());
        }

        try {
            $image->resizeImage($dstW, $dstH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (fill).', previous: $e);
        }
    }

    /**
     * Scale to cover the target area, then crop to center.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeCover(int $dstW, int $dstH, Position $position, bool $upscale): void
    {
        $image  = $this->requireImage();
        $srcW   = $image->getImageWidth();
        $srcH   = $image->getImageHeight();
        $ratio  = max($dstW / $srcW, $dstH / $srcH);

        if (!$upscale) {
            $ratio = min($ratio, 1.0);
        }

        $scaledW = max(1, (int) round($srcW * $ratio));
        $scaledH = max(1, (int) round($srcH * $ratio));

        [$cropX, $cropY] = $position->toOffset($scaledW, $scaledH, $dstW, $dstH);
        $cropX = max(0, $cropX);
        $cropY = max(0, $cropY);

        try {
            $image->resizeImage($scaledW, $scaledH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
            $image->cropImage($dstW, $dstH, $cropX, $cropY);
            $image->setImagePage($dstW, $dstH, 0, 0);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (cover).', previous: $e);
        }
    }

    /**
     * Fit within the target area, then pad with background to reach exact dimensions.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeContain(int $dstW, int $dstH, ?Color $background, bool $upscale): void
    {
        $image = $this->requireImage();
        $srcW  = $image->getImageWidth();
        $srcH  = $image->getImageHeight();
        $ratio = min($dstW / $srcW, $dstH / $srcH);

        if (!$upscale) {
            $ratio = min($ratio, 1.0);
        }

        $fitW    = max(1, (int) round($srcW * $ratio));
        $fitH    = max(1, (int) round($srcH * $ratio));
        $offsetX = (int) (($dstW - $fitW) / self::CENTER_DIVISOR);
        $offsetY = (int) (($dstH - $fitH) / self::CENTER_DIVISOR);

        try {
            $image->resizeImage($fitW, $fitH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);

            $bg     = $background ?? Color::transparent();
            $canvas = new Imagick();
            $canvas->newImage($dstW, $dstH, $this->toImagickPixel($bg), 'png');
            $canvas->compositeImage($image, Imagick::COMPOSITE_OVER, $offsetX, $offsetY);

            $this->imagick = $canvas;
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (contain).', previous: $e);
        }
    }
}
