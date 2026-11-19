<?php

declare(strict_types=1);

namespace G\Image\Driver;

use Closure;
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
 * Animation: a multi-frame image is coalesced when loaded (every frame becomes a
 * full image of the canvas size), every operation is applied to all frames, and
 * GIF and WebP outputs keep the animation; the other formats keep the first frame.
 * Frames are {@see Imagick} instances; delays are given in milliseconds and stored
 * in hundredths of a second (the GIF precision). The pixel limit counts the pixels
 * of all frames.
 *
 * EXIF: readable on load for JPEG; writing throws {@see UnsupportedOperationException}.
 */
final class ImagickDriver implements DriverInterface, DriverAvailabilityInterface
{
    use CapturesNativeWarnings;
    use ValidatesInput;

    // ─── Default encoding quality per format ───────────────────────────────────
    private const DEFAULT_QUALITY_JPEG = 85;
    private const DEFAULT_QUALITY_WEBP = 80;
    private const DEFAULT_QUALITY_AVIF = 75;
    private const DEFAULT_QUALITY_PNG  = 6;

    // ─── PNG "quality" for ImageMagick: tens = zlib level, units = filter ─────
    private const PNG_LEVEL_FACTOR    = 10;
    private const PNG_ADAPTIVE_FILTER = 5;

    // ─── GIF delay ticks per second when the image declares none ──────────────
    private const DEFAULT_TICKS_PER_SECOND = 100;
    private const MILLISECONDS_PER_SECOND  = 1000;

    // ─── Output formats that keep every frame of an animation ─────────────────
    private const ANIMATED_FORMATS = [Format::GIF, Format::WEBP];

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
    private const NO_SCALE           = 1.0;

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
        'addFrame', 'removeFrame', 'setFrameDelay', 'setLoopCount',
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

        // Read by PHP and decoded from memory: a path given to ImageMagick
        // is itself parsed ("format:" prefixes, "[0]" frame selectors...).
        [$data, $warning] = $this->captureWarning(static fn () => file_get_contents($path));
        if ($data === false) {
            throw new ImageLoadException(
                sprintf('Failed to read file: "%s".%s', $path, $warning !== null ? ' ' . $warning : '')
            );
        }

        return $this->fromString($data);
    }

    public function fromUrl(string $url): static
    {
        $this->assertHttpUrl($url);

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
        $this->assertRasterSignature($data);
        $this->assertPixelLimitFromHeader($data);

        try {
            $imagick = new Imagick();
            $imagick->readImageBlob($data);
            $imagick->setIteratorIndex(0);
            $this->assertWithinPixelLimit(
                $imagick->getImageWidth(),
                $imagick->getImageHeight(),
                $imagick->getNumberImages(),
            );
            $imagick = $this->coalesce($imagick);
        } catch (ImagickException $e) {
            throw new ImageLoadException(
                'Imagick could not decode the provided image data.',
                previous: $e,
            );
        }

        $this->imagick = $imagick;
        $this->format  = $this->detectFormatFromImagick();
        $this->exif    = $this->readExif();
        $this->iptc    = $this->readIptc();

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
            $this->assertWithinPixelLimit($blob->getImageWidth(), $blob->getImageHeight(), $blob->getNumberImages());

            try {
                $imagick = clone $blob;
                $imagick->setIteratorIndex(0);
                $this->imagick = $this->coalesce($imagick);
            } catch (ImagickException $e) {
                throw new ImageLoadException('Imagick could not read the provided instance.', previous: $e);
            }

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
            $width = max(1, (int) round($srcW * $height / $srcH));
        } elseif ($height === null) {
            $height = max(1, (int) round($srcH * $width / $srcW));
        }

        $this->assertWithinPixelLimit($width, $height, $image->getNumberImages());

        $this->eachFrame(match ($fit) {
            Fit::FILL  => fn (Imagick $f): Imagick => $this->resizeFill($f, $width, $height, $upscale),
            Fit::COVER => fn (Imagick $f): Imagick => $this->resizeCover($f, $width, $height, $position, $upscale),
            default    => fn (Imagick $f): Imagick => $this->resizeContain($f, $width, $height, $background, $upscale),
        });

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

        $this->assertWithinPixelLimit($newW, $newH, $image->getNumberImages());

        try {
            $this->eachFrame(static function (Imagick $frame) use ($newW, $newH): void {
                $frame->resizeImage($newW, $newH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
            });
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
            $this->eachFrame(static function (Imagick $frame) use ($width, $height, $x, $y): void {
                $frame->cropImage($width, $height, $x, $y);
                $frame->setImagePage($width, $height, 0, 0);
            });
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

            if ($image->getNumberImages() > 1) {
                $this->trimFrames($image, $refPixel, $fuzz);

                return $this;
            }

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

        $pixel = $this->toImagickPixel($bg);

        try {
            // Imagick rotates clockwise — matches our API (no negation needed)
            $this->eachFrame(static function (Imagick $frame) use ($pixel, $angle): void {
                $frame->rotateImage($pixel, $angle);
                $frame->setImagePage($frame->getImageWidth(), $frame->getImageHeight(), 0, 0);
            });
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to rotate the image.', previous: $e);
        }

        return $this;
    }

    public function flip(Flip $direction): static
    {
        try {
            $this->eachFrame(static function (Imagick $frame) use ($direction): void {
                if ($direction === Flip::HORIZONTAL || $direction === Flip::BOTH) {
                    // flopImage = horizontal flip (mirror left-right)
                    $frame->flopImage();
                }

                if ($direction === Flip::VERTICAL || $direction === Flip::BOTH) {
                    // flipImage = vertical flip (mirror top-bottom)
                    $frame->flipImage();
                }
            });
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

        try {
            $this->eachFrame(static function (Imagick $frame) use ($amount): void {
                for ($i = 0; $i < $amount; $i++) {
                    $frame->gaussianBlurImage(self::BLUR_RADIUS, self::BLUR_SIGMA);
                }
            });
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

        try {
            $this->eachFrame(static function (Imagick $frame) use ($amount): void {
                for ($i = 0; $i < $amount; $i++) {
                    // radius=0 lets Imagick auto-calculate; sigma controls the spread
                    $frame->unsharpMaskImage(0.0, self::SHARPEN_SIGMA, self::SHARPEN_AMOUNT, self::SHARPEN_THRESHOLD);
                }
            });
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
            $this->eachFrame(static function (Imagick $frame) use ($modulateBrightness): void {
                $frame->modulateImage(
                    $modulateBrightness,
                    (float) self::MODULATE_NEUTRAL,
                    (float) self::MODULATE_NEUTRAL,
                );
            });
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
            $this->eachFrame(static function (Imagick $frame) use ($level): void {
                $frame->brightnessContrastImage(0.0, (float) $level);
            });
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to adjust contrast.', previous: $e);
        }

        return $this;
    }

    public function grayscale(): static
    {
        try {
            $this->eachFrame(static function (Imagick $frame): void {
                $frame->transformImageColorspace(Imagick::COLORSPACE_GRAY);
                // Convert back to sRGB to maintain broad format compatibility
                $frame->transformImageColorspace(Imagick::COLORSPACE_SRGB);
            });
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply grayscale.', previous: $e);
        }

        return $this;
    }

    public function negate(): static
    {
        try {
            $this->eachFrame(static function (Imagick $frame): void {
                $frame->negateImage(false);
            });
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
            $this->eachFrame(static function (Imagick $frame) use ($threshold): void {
                $frame->sepiaToneImage($threshold);
            });
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to apply sepia tone.', previous: $e);
        }

        return $this;
    }

    public function emboss(): static
    {
        try {
            $this->eachFrame(static function (Imagick $frame): void {
                $frame->embossImage(self::EMBOSS_RADIUS, self::EMBOSS_SIGMA);
            });
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
        $this->assertLossyQuality($quality, 'JPEG');
        $this->format  = Format::JPEG;
        $this->quality = $quality;

        return $this;
    }

    public function toPng(?int $quality = null): static
    {
        $this->assertPngCompression($quality);
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

        $this->assertLossyQuality($quality, 'WebP');
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

        $this->assertLossyQuality($quality, 'AVIF');
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

        $this->assertBmpCompression($quality);
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

        $this->assertLossyQuality($quality, 'HEIC');
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
            // First frame only: an animated watermark is laid as a still image
            $wmImagick = $this->framesOf($watermark->imagick)[0];
        } elseif ($watermark instanceof OutputInterface) {
            try {
                $wmData = $watermark->toString();
                $this->assertRasterSignature($wmData);
                $wmImagick = new Imagick();
                $wmImagick->readImageBlob($wmData);
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

            $this->eachFrame(static function (Imagick $frame) use ($wmImagick, $x, $y): void {
                $frame->compositeImage($wmImagick, Imagick::COMPOSITE_OVER, $x, $y);
            });
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
            $this->eachFrame(static function (Imagick $f) use ($draw, $originX, $baselineY, $angle, $content): void {
                $f->annotateImage($draw, $originX, $baselineY, $angle, $content);
            });
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

        $this->requireImage();
        $x2    = $x + $width - 1;
        $y2    = $y + $height - 1;

        try {
            if ($fill !== null) {
                $draw = new ImagickDraw();
                $draw->setFillColor($this->toImagickPixel($fill));
                $draw->setStrokeWidth(0.0);
                $draw->setStrokeColor(new ImagickPixel('none'));
                $draw->rectangle((float) $x, (float) $y, (float) $x2, (float) $y2);
                $this->drawOnFrames($draw);
            }

            if (!$stroke->isTransparent()) {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('none'));
                $draw->setStrokeColor($this->toImagickPixel($stroke));
                $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
                $draw->rectangle((float) $x, (float) $y, (float) $x2, (float) $y2);
                $this->drawOnFrames($draw);
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

        $this->requireImage();
        $rx    = $width  / self::CENTER_DIVISOR;
        $ry    = $height / self::CENTER_DIVISOR;

        try {
            if ($fill !== null) {
                $draw = new ImagickDraw();
                $draw->setFillColor($this->toImagickPixel($fill));
                $draw->setStrokeWidth(0.0);
                $draw->setStrokeColor(new ImagickPixel('none'));
                $draw->ellipse((float) $cx, (float) $cy, $rx, $ry, 0.0, self::FULL_CIRCLE_DEG);
                $this->drawOnFrames($draw);
            }

            if (!$stroke->isTransparent()) {
                $draw = new ImagickDraw();
                $draw->setFillColor(new ImagickPixel('none'));
                $draw->setStrokeColor($this->toImagickPixel($stroke));
                $draw->setStrokeWidth(self::STROKE_WIDTH_THIN);
                $draw->ellipse((float) $cx, (float) $cy, $rx, $ry, 0.0, self::FULL_CIRCLE_DEG);
                $this->drawOnFrames($draw);
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
            $this->drawOnFrames($draw);
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

        $this->requireImage();
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
                $this->drawOnFrames($draw);
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
                $this->drawOnFrames($draw);
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

        return $this->framesOf($this->imagick);
    }

    public function getFrame(int $index): mixed
    {
        $this->assertFrameIndex($index);

        /** @var Imagick $imagick */
        $imagick = $this->imagick;
        $imagick->setIteratorIndex($index);
        $frame = $imagick->getImage();
        $imagick->setIteratorIndex(0);

        return $frame;
    }

    /**
     * The frame is an {@see Imagick} instance (its first image is used) with the
     * dimensions of the loaded image.
     */
    public function addFrame(mixed $frame, int $delay, ?int $index = null): static
    {
        $image = $this->requireImage();
        $count = $image->getNumberImages();
        $index ??= $count;

        if (!$frame instanceof Imagick) {
            throw new InvalidImageArgumentException('ImagickDriver::addFrame() expects an Imagick instance.');
        }

        $this->assertDelay($delay);

        if ($index < 0 || $index > $count) {
            throw new InvalidImageArgumentException(
                sprintf('Frame insertion index %d is out of bounds (0 to %d).', $index, $count)
            );
        }

        try {
            $new = $this->framesOf($frame)[0];
            $width  = $image->getImageWidth();
            $height = $image->getImageHeight();

            if ($new->getImageWidth() !== $width || $new->getImageHeight() !== $height) {
                throw new InvalidImageArgumentException(sprintf(
                    'The frame must measure %d×%d, like the image; %d×%d given.',
                    $width,
                    $height,
                    $new->getImageWidth(),
                    $new->getImageHeight(),
                ));
            }

            $this->assertWithinPixelLimit($width, $height, $count + 1);

            $new->setImagePage($width, $height, 0, 0);
            $new->setImageIterations($image->getImageIterations());
            $this->applyDelay($new, $delay);

            $frames = $this->framesOf($image);
            array_splice($frames, $index, 0, [$new]);
            $this->imagick = $this->sequence($frames);
        } catch (ImagickException $e) {
            throw new InvalidImageArgumentException('Imagick could not add the frame.', previous: $e);
        }

        return $this;
    }

    public function removeFrame(int $index): static
    {
        $this->assertFrameIndex($index);

        /** @var Imagick $image */
        $image = $this->imagick;
        if ($image->getNumberImages() === 1) {
            throw new InvalidImageArgumentException('The only frame of an image cannot be removed.');
        }

        try {
            $frames = $this->framesOf($image);
            $iterations = $image->getImageIterations();
            array_splice($frames, $index, 1);
            /** @var non-empty-list<Imagick> $frames */
            $frames[0]->setImageIterations($iterations);
            $this->imagick = $this->sequence($frames);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick could not remove the frame.', previous: $e);
        }

        return $this;
    }

    public function getFrameDelay(int $index): int
    {
        $this->assertFrameIndex($index);

        /** @var Imagick $imagick */
        $imagick = $this->imagick;
        $imagick->setIteratorIndex($index);
        $delay = $imagick->getImageDelay();
        $ticks = $imagick->getImageTicksPerSecond();
        $imagick->setIteratorIndex(0);

        // ImageMagick counts in ticks (1/100 s for GIF); the API in milliseconds.
        $ticks = $ticks > 0 ? $ticks : self::DEFAULT_TICKS_PER_SECOND;

        return intdiv($delay * self::MILLISECONDS_PER_SECOND, $ticks);
    }

    /**
     * The delay is stored in hundredths of a second (GIF precision): 25 ms becomes 30 ms.
     */
    public function setFrameDelay(int $index, int $delay): static
    {
        $this->assertFrameIndex($index);
        $this->assertDelay($delay);

        /** @var Imagick $imagick */
        $imagick = $this->imagick;

        try {
            $imagick->setIteratorIndex($index);
            $this->applyDelay($imagick, $delay);
        } catch (ImagickException $e) {
            throw new InvalidImageArgumentException('Imagick could not set the frame delay.', previous: $e);
        } finally {
            $imagick->setIteratorIndex(0);
        }

        return $this;
    }

    public function getLoopCount(): int
    {
        if ($this->imagick === null) {
            return 0;
        }

        $this->imagick->setIteratorIndex(0);

        return max(0, $this->imagick->getImageIterations());
    }

    public function setLoopCount(int $count): static
    {
        if ($count < 0) {
            throw new InvalidImageArgumentException(
                sprintf('Loop count must be zero (infinite) or a positive integer, %d given.', $count)
            );
        }

        $imagick = $this->requireImage();

        try {
            // Every frame carries it: GIF reads the first one, other writers may read any
            $frames = $imagick->getNumberImages();
            for ($i = 0; $i < $frames; $i++) {
                $imagick->setIteratorIndex($i);
                $imagick->setImageIterations($count);
            }
        } catch (ImagickException $e) {
            throw new InvalidImageArgumentException('Imagick could not set the loop count.', previous: $e);
        } finally {
            $imagick->setIteratorIndex(0);
        }

        return $this;
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
     * @param int $frames Number of frames of these dimensions (all of them are held in memory).
     *
     * @throws ImageLimitExceededException When the limit is configured and exceeded.
     */
    private function assertWithinPixelLimit(int $width, int $height, int $frames = 1): void
    {
        if ($this->maxPixels === null) {
            return;
        }

        $pixelCount = $width * $height * max(1, $frames);
        if ($pixelCount > $this->maxPixels) {
            throw new ImageLimitExceededException($pixelCount, $this->maxPixels);
        }
    }

    /**
     * Enforce the pixel limit from the header of encoded image data, without decoding it.
     *
     * The dimensions are read by PHP itself first (getimagesizefromstring() only
     * parses the header and knows the common formats), then by
     * {@see Imagick::pingImageBlob()} for the others: depending on its version
     * and security policy, ImageMagick may refuse to ping an oversized or
     * truncated image, which must not hide the limit violation.
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

        [$info] = $this->captureWarning(static fn () => getimagesizefromstring($data));
        if (is_array($info)) {
            $this->assertWithinPixelLimit((int) $info[0], (int) $info[1]);

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
            $magick      = $this->formatToImagickString($format);
            $compression = $this->imagickQuality($format, $quality);
            $frames      = $this->framesOf($image);

            // Formats without animation keep the first frame
            if (count($frames) === 1 || !in_array($format, self::ANIMATED_FORMATS, strict: true)) {
                $frames[0]->setImageFormat($magick);
                $frames[0]->setImageCompressionQuality($compression);

                return $frames[0]->getImageBlob();
            }

            foreach ($frames as $frame) {
                $frame->setImageFormat($magick);
                $frame->setImageCompressionQuality($compression);
                // Frames are coalesced (full images): each one replaces the previous
                $frame->setImageDispose(Imagick::DISPOSE_BACKGROUND);
            }

            $sequence = $this->sequence($frames);
            $sequence->setFormat($magick);

            return $sequence->getImagesBlob();
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
     * The value given to setImageCompressionQuality(). For PNG, ImageMagick
     * reads it as two digits: the zlib level (tens) and the row filter
     * (units); the API's 0-9 compression level becomes "level, adaptive
     * filter" (6 → 65), where a bare 6 would mean "no compression".
     */
    private function imagickQuality(Format $format, ?int $quality): int
    {
        $value = $quality ?? $this->defaultQuality($format);

        return $format === Format::PNG
            ? $value * self::PNG_LEVEL_FACTOR + self::PNG_ADAPTIVE_FILTER
            : $value;
    }

    /**
     * Read the IPTC profile of the loaded image, keyed like iptcparse().
     *
     * @return array<string, mixed>
     */
    private function readIptc(): array
    {
        if ($this->imagick === null) {
            return [];
        }

        try {
            $profiles = $this->imagick->getImageProfiles('iptc');
        } catch (ImagickException) {
            return [];
        }

        $raw = $profiles['iptc'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [];
        }

        [$iptc] = $this->captureWarning(static fn () => iptcparse($raw));

        /** @var array<string, mixed> $parsed */
        $parsed = is_array($iptc) ? $iptc : [];

        return $parsed;
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
     * Resize a frame to exact dimensions, optionally preventing upscaling.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeFill(Imagick $frame, int $dstW, int $dstH, bool $upscale): Imagick
    {
        if (!$upscale) {
            $dstW = min($dstW, $frame->getImageWidth());
            $dstH = min($dstH, $frame->getImageHeight());
        }

        try {
            $frame->resizeImage($dstW, $dstH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (fill).', previous: $e);
        }

        return $frame;
    }

    /**
     * Scale a frame to cover the target area, then crop it at $position.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeCover(Imagick $frame, int $dstW, int $dstH, Position $position, bool $upscale): Imagick
    {
        $srcW  = $frame->getImageWidth();
        $srcH  = $frame->getImageHeight();
        $ratio = max($dstW / $srcW, $dstH / $srcH);

        // Without upscaling a source smaller than the target is never
        // stretched: the canvas keeps the requested size and receives the
        // source as is (cropped where it is larger), placed at $position.
        if (!$upscale && $ratio > self::NO_SCALE) {
            $cropW = min($dstW, $srcW);
            $cropH = min($dstH, $srcH);
            [$fromX, $fromY] = $position->toOffset($srcW, $srcH, $cropW, $cropH);
            [$toX, $toY]     = $position->toOffset($dstW, $dstH, $cropW, $cropH);

            try {
                $frame->cropImage($cropW, $cropH, $fromX, $fromY);
                $frame->setImagePage(0, 0, 0, 0);

                $canvas = new Imagick();
                $canvas->newImage($dstW, $dstH, $this->toImagickPixel(Color::transparent()), 'png');
                $canvas->compositeImage($frame, Imagick::COMPOSITE_OVER, $toX, $toY);
            } catch (ImagickException $e) {
                throw new ImageLoadException('Imagick failed to resize (cover).', previous: $e);
            }

            return $canvas;
        }

        $scaledW = max(1, (int) round($srcW * $ratio));
        $scaledH = max(1, (int) round($srcH * $ratio));

        [$cropX, $cropY] = $position->toOffset($scaledW, $scaledH, $dstW, $dstH);
        $cropX = max(0, $cropX);
        $cropY = max(0, $cropY);

        try {
            $frame->resizeImage($scaledW, $scaledH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);
            $frame->cropImage($dstW, $dstH, $cropX, $cropY);
            $frame->setImagePage($dstW, $dstH, 0, 0);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (cover).', previous: $e);
        }

        return $frame;
    }

    /**
     * Fit a frame within the target area, then pad it with background to reach exact dimensions.
     *
     * @throws ImageLoadException On Imagick failure.
     */
    private function resizeContain(Imagick $frame, int $dstW, int $dstH, ?Color $background, bool $upscale): Imagick
    {
        $srcW  = $frame->getImageWidth();
        $srcH  = $frame->getImageHeight();
        $ratio = min($dstW / $srcW, $dstH / $srcH);

        if (!$upscale) {
            $ratio = min($ratio, self::NO_SCALE);
        }

        $fitW    = max(1, (int) round($srcW * $ratio));
        $fitH    = max(1, (int) round($srcH * $ratio));
        $offsetX = (int) (($dstW - $fitW) / self::CENTER_DIVISOR);
        $offsetY = (int) (($dstH - $fitH) / self::CENTER_DIVISOR);

        try {
            $frame->resizeImage($fitW, $fitH, Imagick::FILTER_LANCZOS, self::IMAGICK_FILTER_BLUR);

            $bg     = $background ?? Color::transparent();
            $canvas = new Imagick();
            $canvas->newImage($dstW, $dstH, $this->toImagickPixel($bg), 'png');
            $canvas->compositeImage($frame, Imagick::COMPOSITE_OVER, $offsetX, $offsetY);
        } catch (ImagickException $e) {
            throw new ImageLoadException('Imagick failed to resize (contain).', previous: $e);
        }

        return $canvas;
    }

    // ─── Frame helpers ────────────────────────────────────────────────────────

    /**
     * Apply an operation to every frame of the loaded image.
     *
     * The operation changes the frame in place, or returns the frame replacing it
     * (a new canvas); a replacing frame inherits the timing of the original one.
     *
     * @param Closure(Imagick): (Imagick|void) $operation
     *
     * @throws ImagickException
     */
    private function eachFrame(Closure $operation): void
    {
        $image = $this->requireImage();

        if ($image->getNumberImages() === 1) {
            $result = $operation($image);
            if ($result instanceof Imagick) {
                $this->imagick = $result;
            }

            return;
        }

        $frames = [];
        foreach ($this->framesOf($image) as $frame) {
            $result = $operation($frame);
            if ($result instanceof Imagick && $result !== $frame) {
                $this->copyTiming($frame, $result);
                $frame = $result;
            }

            $frames[] = $frame;
        }

        $this->imagick = $this->sequence($frames);
    }

    /**
     * Draw on every frame of the loaded image.
     *
     * @throws ImagickException
     */
    private function drawOnFrames(ImagickDraw $draw): void
    {
        $this->eachFrame(static function (Imagick $frame) use ($draw): void {
            $frame->drawImage($draw);
        });
    }

    /**
     * Trim every frame to the same box: the union of what each frame would keep.
     *
     * @throws ImagickException
     */
    private function trimFrames(Imagick $image, ImagickPixel $reference, float $fuzz): void
    {
        $left   = PHP_INT_MAX;
        $top    = PHP_INT_MAX;
        $right  = 0;
        $bottom = 0;

        foreach ($this->framesOf($image) as $frame) {
            $frame->setImageBackgroundColor($reference);
            $frame->trimImage($fuzz);
            $page   = $frame->getImagePage();
            $left   = min($left, $page['x']);
            $top    = min($top, $page['y']);
            $right  = max($right, $page['x'] + $frame->getImageWidth());
            $bottom = max($bottom, $page['y'] + $frame->getImageHeight());
        }

        $width  = $right - $left;
        $height = $bottom - $top;

        $this->eachFrame(static function (Imagick $frame) use ($width, $height, $left, $top): void {
            $frame->cropImage($width, $height, $left, $top);
            $frame->setImagePage($width, $height, 0, 0);
        });
    }

    /**
     * Each image of a sequence as a separate Imagick instance, in order.
     *
     * @return non-empty-list<Imagick>
     *
     * @throws ImagickException
     */
    private function framesOf(Imagick $image): array
    {
        $current = $image->getIteratorIndex();
        $frames  = [];
        $count   = $image->getNumberImages();

        for ($i = 0; $i < $count; $i++) {
            $image->setIteratorIndex($i);
            $frames[] = $image->getImage();
        }

        $image->setIteratorIndex($current);

        if ($frames === []) {
            throw new ImageLoadException('The image has no frame.');
        }

        return $frames;
    }

    /**
     * Assemble frames into one sequence, positioned on its first frame.
     *
     * @param non-empty-list<Imagick> $frames
     *
     * @throws ImagickException
     */
    private function sequence(array $frames): Imagick
    {
        $sequence = new Imagick();
        foreach ($frames as $frame) {
            $sequence->addImage($frame);
        }

        $sequence->setIteratorIndex(0);

        return $sequence;
    }

    /**
     * Coalesce an animation: every frame becomes a full image of the canvas size,
     * so that operations and frame edits never see partial frames.
     *
     * @throws ImagickException
     */
    private function coalesce(Imagick $image): Imagick
    {
        if ($image->getNumberImages() <= 1) {
            return $image;
        }

        $coalesced = $image->coalesceImages();
        $coalesced->setIteratorIndex(0);

        return $coalesced;
    }

    /**
     * Copy the animation settings of a frame to the frame replacing it.
     *
     * @throws ImagickException
     */
    private function copyTiming(Imagick $from, Imagick $to): void
    {
        $to->setImageDelay($from->getImageDelay());
        $to->setImageTicksPerSecond($from->getImageTicksPerSecond());
        $to->setImageIterations($from->getImageIterations());
    }

    /**
     * Throw unless $index designates an existing frame.
     *
     * @throws InvalidImageArgumentException
     */
    private function assertFrameIndex(int $index): void
    {
        $count = $this->getFrameCount();

        if (!$this->isLoaded() || $index < 0 || $index >= $count) {
            throw new InvalidImageArgumentException(
                sprintf('Frame index %d is out of bounds (ImagickDriver has %d frame(s)).', $index, $count)
            );
        }
    }

    /**
     * @throws InvalidImageArgumentException When the delay is negative.
     */
    private function assertDelay(int $delay): void
    {
        if ($delay < 0) {
            throw new InvalidImageArgumentException(
                sprintf('Frame delay must be zero or a positive number of milliseconds, %d given.', $delay)
            );
        }
    }

    /**
     * Store a delay given in milliseconds, in hundredths of a second (GIF precision).
     *
     * @throws ImagickException
     */
    private function applyDelay(Imagick $frame, int $delay): void
    {
        $frame->setImageTicksPerSecond(self::DEFAULT_TICKS_PER_SECOND);
        $frame->setImageDelay(
            (int) round($delay * self::DEFAULT_TICKS_PER_SECOND / self::MILLISECONDS_PER_SECOND)
        );
    }
}
