<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Color\Color;
use G\Image\Enum\Fit;
use G\Image\Enum\Flip;
use G\Image\Enum\Format;
use G\Image\Enum\Position;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\ImageSaveException;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedFormatException;
use G\Image\Exception\UnsupportedOperationException;
use G\Image\ImageInterface;
use G\Image\Io\OutputInterface;
use GdImage;
use Psr\Http\Message\StreamInterface;

/**
 * GD-based image driver.
 *
 * Implements the full {@see DriverInterface} contract using PHP's built-in GD
 * extension. Requires PHP >= 8.2 with `ext-gd` enabled.
 *
 * Supported formats: JPEG, PNG, GIF, WebP (if compiled), AVIF (PHP 8.1+), BMP (PHP 7.2+).
 * Not supported: TIFF, HEIC (throw {@see UnsupportedFormatException}).
 * Animation: GD only handles single frames; multi-frame methods throw
 * {@see UnsupportedOperationException}.
 * EXIF: readable for JPEG on load; writing throws {@see UnsupportedOperationException}.
 */
final class GDDriver implements DriverInterface
{
    // ─── GD alpha (0 = opaque, 127 = transparent) ─────────────────────────────
    private const GD_ALPHA_TRANSPARENT = 127;

    // ─── Default encoding quality per format ───────────────────────────────────
    private const DEFAULT_QUALITY_JPEG = 85;
    private const DEFAULT_QUALITY_WEBP = 80;
    private const DEFAULT_QUALITY_AVIF = 75;
    private const DEFAULT_QUALITY_PNG  = 6;

    // ─── Filter level range ────────────────────────────────────────────────────
    private const LEVEL_MIN         = -100;
    private const LEVEL_MAX         =  100;
    private const BRIGHTNESS_GD_MAX =  255;

    // ─── Sharpen convolution kernel ────────────────────────────────────────────
    // Kernel: [0,-a,0; -a,1+4a,-a; 0,-a,0] — sums to 1, sharpens with intensity `a`
    private const SHARPEN_SURROUNDING = 4;

    // ─── Sepia colorize tint (GD IMG_FILTER_COLORIZE values) ──────────────────
    private const SEPIA_RED   = 112;
    private const SEPIA_GREEN =  66;
    private const SEPIA_BLUE  =  20;

    // ─── Scale / dimension helpers ─────────────────────────────────────────────
    private const PERCENTAGE_DIVISOR = 100;
    private const CENTER_DIVISOR     =   2;

    // ─── Color channel max ─────────────────────────────────────────────────────
    private const CHANNEL_MAX  = 255;
    private const ALPHA_OPAQUE = 1.0;

    // ─── HTTP fetch timeout (seconds) ─────────────────────────────────────────
    private const HTTP_TIMEOUT = 10;

    // ─── Operations supported by this driver ──────────────────────────────────
    private const SUPPORTED_OPERATIONS = [
        'resize', 'scale',
        'crop', 'trim',
        'rotate', 'flip',
        'blur', 'sharpen', 'brightness', 'contrast', 'grayscale', 'negate', 'sepia', 'emboss',
        'toJpeg', 'toPng', 'toWebp', 'toAvif', 'toGif', 'toBmp', 'toFormat',
        'watermark', 'text', 'rect', 'ellipse', 'line', 'arc',
        'getWidth', 'getHeight', 'getFormat', 'getExif', 'getIptc', 'stripMetadata',
        'getFrameCount', 'getFrames', 'getFrame', 'getFrameDelay', 'getLoopCount',
        'isLoaded', 'copy', 'destroy',
        'supportsFormat', 'supportsOperation', 'getSupportedFormats',
    ];

    // ─── State ─────────────────────────────────────────────────────────────────
    private ?GdImage $image = null;

    private Format $format = Format::JPEG;

    private ?int $quality = null;

    /** @var array<string, mixed> */
    private array $exif = [];

    /** @var array<string, mixed> */
    private array $iptc = [];

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @throws UnsupportedOperationException When the GD extension is not loaded.
     */
    public function __construct()
    {
        if (!extension_loaded('gd')) {
            throw new UnsupportedOperationException(
                'The GD extension (ext-gd) is required to use GDDriver.'
            );
        }
    }

    // =========================================================================
    // InputInterface
    // =========================================================================

    public function fromFile(string $path): static
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageLoadException(
                sprintf('File not found or not readable: "%s".', $path)
            );
        }

        $data = file_get_contents($path);
        if ($data === false) {
            throw new ImageLoadException(sprintf('Failed to read file: "%s".', $path));
        }

        $this->loadRawData($data);
        $this->format = $this->detectFormat($data);
        $this->exif   = [];
        $this->iptc   = [];

        if ($this->format === Format::JPEG && function_exists('exif_read_data')) {
            $raw = @exif_read_data($path);
            /** @var array<string, mixed> $safe */
            $safe = is_array($raw) ? $raw : [];
            $this->exif = $safe;
        }

        return $this;
    }

    public function fromUrl(string $url): static
    {
        $context = stream_context_create([
            'http'  => ['timeout' => self::HTTP_TIMEOUT, 'method' => 'GET'],
            'https' => ['timeout' => self::HTTP_TIMEOUT, 'method' => 'GET'],
        ]);

        $data = @file_get_contents($url, false, $context);
        if ($data === false) {
            throw new ImageLoadException(
                sprintf('Failed to fetch image from URL: "%s".', $url)
            );
        }

        return $this->fromString($data);
    }

    public function fromString(string $data): static
    {
        $this->loadRawData($data);
        $this->format = $this->detectFormat($data);
        $this->exif   = [];
        $this->iptc   = [];

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
        if ($blob instanceof GdImage) {
            $this->image  = $blob;
            $this->exif   = [];
            $this->iptc   = [];

            return $this;
        }

        if (is_string($blob)) {
            return $this->fromString($blob);
        }

        throw new ImageLoadException(
            'GDDriver::fromBlob() expects a GdImage instance or a raw binary string.'
        );
    }

    public function fromResource(mixed $resource): static
    {
        if (!is_resource($resource)) {
            throw new ImageLoadException(
                'GDDriver::fromResource() expects a valid PHP stream resource.'
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
        if (file_put_contents($path, $data) === false) {
            throw new ImageSaveException(
                sprintf('Failed to write image to: "%s".', $path)
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
     * Returns the underlying {@see GdImage} resource.
     *
     * Can be passed back to {@see fromBlob()} on another GDDriver instance
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
                'GDDriver::toResource() expects a valid PHP stream resource.'
            );
        }

        $data = $this->toString();
        if (fwrite($resource, $data) === false) {
            throw new ImageSaveException('Failed to write image data to the resource.');
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

        $src  = $this->requireImage();
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($width === null) {
            $width = (int) round($srcW * $height / $srcH);
        } elseif ($height === null) {
            $height = (int) round($srcH * $width / $srcW);
        }

        $this->image = match ($fit) {
            Fit::FILL    => $this->resizeFill($src, $srcW, $srcH, $width, $height, $upscale),
            Fit::COVER   => $this->resizeCover($src, $srcW, $srcH, $width, $height, $position, $upscale),
            Fit::CONTAIN => $this->resizeContain($src, $srcW, $srcH, $width, $height, $background, $upscale),
        };

        return $this;
    }

    public function scale(int $percentage): static
    {
        if ($percentage <= 0) {
            throw new InvalidImageArgumentException(
                sprintf('Scale percentage must be a positive integer, %d given.', $percentage)
            );
        }

        $src  = $this->requireImage();
        $newW = (int) round(imagesx($src) * $percentage / self::PERCENTAGE_DIVISOR);
        $newH = (int) round(imagesy($src) * $percentage / self::PERCENTAGE_DIVISOR);

        if ($newW <= 0 || $newH <= 0) {
            throw new InvalidImageArgumentException(
                'Resulting dimensions after scale are too small (less than 1 pixel).'
            );
        }

        $dst = $this->createCanvas($newW, $newH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, imagesx($src), imagesy($src));
        $this->image = $dst;

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

        $src  = $this->requireImage();
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($x < 0 || $y < 0 || $x + $width > $srcW || $y + $height > $srcH) {
            throw new InvalidImageArgumentException(
                'Crop region extends beyond the image boundaries.'
            );
        }

        $result = imagecrop($src, ['x' => $x, 'y' => $y, 'width' => $width, 'height' => $height]);
        if ($result === false) {
            throw new ImageLoadException('GD failed to crop the image.');
        }

        $this->image = $result;

        return $this;
    }

    public function trim(int $threshold = 0, Position $corner = Position::TOP_LEFT): static
    {
        if ($threshold < 0 || $threshold > self::CHANNEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Trim threshold must be between 0 and %d.', self::CHANNEL_MAX)
            );
        }

        $src = $this->requireImage();
        $w   = imagesx($src);
        $h   = imagesy($src);

        [$refX, $refY] = $corner->toOffset($w, $h, 1, 1);

        $refIndex = imagecolorat($src, $refX, $refY);
        if ($refIndex === false) {
            throw new ImageLoadException('Failed to sample the reference pixel for trim.');
        }

        $ref  = imagecolorsforindex($src, $refIndex);
        $refR = $ref['red'];
        $refG = $ref['green'];
        $refB = $ref['blue'];

        $left   = $w;
        $top    = $h;
        $right  = 0;
        $bottom = 0;

        for ($py = 0; $py < $h; $py++) {
            for ($px = 0; $px < $w; $px++) {
                $idx = imagecolorat($src, $px, $py);
                if ($idx === false) {
                    continue;
                }

                $c    = imagecolorsforindex($src, $idx);
                $diff = max(
                    abs($c['red']   - $refR),
                    abs($c['green'] - $refG),
                    abs($c['blue']  - $refB),
                );

                if ($diff > $threshold) {
                    if ($px < $left) {
                        $left   = $px;
                    }
                    if ($py < $top) {
                        $top    = $py;
                    }
                    if ($px > $right) {
                        $right  = $px;
                    }
                    if ($py > $bottom) {
                        $bottom = $py;
                    }
                }
            }
        }

        if ($right < $left || $bottom < $top) {
            return $this;
        }

        return $this->crop($right - $left + 1, $bottom - $top + 1, $left, $top);
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

        $src     = $this->requireImage();
        $bg      = $background ?? Color::transparent();
        $bgColor = $this->allocateColor($src, $bg);

        // GD rotates counter-clockwise; negate for a clockwise API
        $result = imagerotate($src, -$angle, $bgColor);
        if ($result === false) {
            throw new ImageLoadException('GD failed to rotate the image.');
        }

        imagealphablending($result, false);
        imagesavealpha($result, true);
        $this->image = $result;

        return $this;
    }

    public function flip(Flip $direction): static
    {
        $src  = $this->requireImage();
        $mode = match ($direction) {
            Flip::HORIZONTAL => IMG_FLIP_HORIZONTAL,
            Flip::VERTICAL   => IMG_FLIP_VERTICAL,
            Flip::BOTH       => IMG_FLIP_BOTH,
        };

        imageflip($src, $mode);

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

        $src = $this->requireImage();
        for ($i = 0; $i < $amount; $i++) {
            imagefilter($src, IMG_FILTER_GAUSSIAN_BLUR);
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

        $src    = $this->requireImage();
        $center = (float) (1 + self::SHARPEN_SURROUNDING * $amount);
        $edge   = (float) (-$amount);

        $kernel = [
            [0.0, $edge, 0.0],
            [$edge, $center, $edge],
            [0.0, $edge, 0.0],
        ];

        imageconvolution($src, $kernel, 1.0, 0.0);

        return $this;
    }

    public function brightness(int $level): static
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Brightness level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX)
            );
        }

        $gdLevel = (int) round($level * self::BRIGHTNESS_GD_MAX / self::LEVEL_MAX);
        imagefilter($this->requireImage(), IMG_FILTER_BRIGHTNESS, $gdLevel);

        return $this;
    }

    public function contrast(int $level): static
    {
        if ($level < self::LEVEL_MIN || $level > self::LEVEL_MAX) {
            throw new InvalidImageArgumentException(
                sprintf('Contrast level must be between %d and %d.', self::LEVEL_MIN, self::LEVEL_MAX)
            );
        }

        // GD's contrast is inverted: positive = less contrast, negative = more contrast
        imagefilter($this->requireImage(), IMG_FILTER_CONTRAST, -$level);

        return $this;
    }

    public function grayscale(): static
    {
        imagefilter($this->requireImage(), IMG_FILTER_GRAYSCALE);

        return $this;
    }

    public function negate(): static
    {
        imagefilter($this->requireImage(), IMG_FILTER_NEGATE);

        return $this;
    }

    public function sepia(): static
    {
        $src = $this->requireImage();
        imagefilter($src, IMG_FILTER_GRAYSCALE);
        imagefilter($src, IMG_FILTER_COLORIZE, self::SEPIA_RED, self::SEPIA_GREEN, self::SEPIA_BLUE);

        return $this;
    }

    public function emboss(): static
    {
        imagefilter($this->requireImage(), IMG_FILTER_EMBOSS);

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
        if (!function_exists('imagewebp')) {
            throw new UnsupportedFormatException(
                'WebP output is not supported by this GD build.'
            );
        }

        $this->format  = Format::WEBP;
        $this->quality = $quality;

        return $this;
    }

    public function toAvif(?int $quality = null): static
    {
        if (!function_exists('imageavif')) {
            throw new UnsupportedFormatException(
                'AVIF output is not supported by this GD build (requires PHP 8.1+ with AVIF support).'
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
        if (!function_exists('imagebmp')) {
            throw new UnsupportedFormatException(
                'BMP output is not supported by this GD build.'
            );
        }

        $this->format  = Format::BMP;
        $this->quality = $quality;

        return $this;
    }

    public function toTiff(?int $quality = null): static
    {
        throw new UnsupportedFormatException('TIFF output is not supported by the GD driver.');
    }

    public function toHeic(?int $quality = null): static
    {
        throw new UnsupportedFormatException('HEIC output is not supported by the GD driver.');
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
                sprintf('Format "%s" is not supported by the GD driver.', $format->value)
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

        // Same-driver fast path: access GdImage directly
        if ($watermark instanceof self && $watermark->image !== null) {
            $wmImage = $watermark->image;
        } elseif ($watermark instanceof OutputInterface) {
            $wmImage = $this->buildGdImage($watermark->toString());
        } else {
            throw new UnsupportedOperationException(
                'The watermark must be a GDDriver instance or implement OutputInterface.'
            );
        }

        $wmW  = imagesx($wmImage);
        $wmH  = imagesy($wmImage);
        $dstW = imagesx($dst);
        $dstH = imagesy($dst);

        [$x, $y] = $position->toOffset($dstW, $dstH, $wmW, $wmH);

        imagecopymerge($dst, $wmImage, $x, $y, 0, 0, $wmW, $wmH, $opacity);

        return $this;
    }

    public function text(
        string $content,
        int $x,
        int $y,
        string $fontPath,
        int $fontSize,
        Color $color,
        float $angle = 0.0,
    ): static {
        if ($fontSize <= 0) {
            throw new InvalidImageArgumentException(
                'Font size must be a positive integer.'
            );
        }

        if (!is_file($fontPath) || !is_readable($fontPath)) {
            throw new ImageLoadException(
                sprintf('Font file not found or not readable: "%s".', $fontPath)
            );
        }

        $src        = $this->requireImage();
        $colorIndex = $this->allocateColor($src, $color);

        // GD angle is counter-clockwise; negate for clockwise API
        $result = imagettftext($src, (float) $fontSize, -$angle, $x, $y, $colorIndex, $fontPath, $content);
        if ($result === false) {
            throw new ImageLoadException(
                'Failed to render text (FreeType support may not be compiled into GD).'
            );
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

        $src = $this->requireImage();
        $x2  = $x + $width - 1;
        $y2  = $y + $height - 1;

        if ($fill !== null) {
            imagefilledrectangle($src, $x, $y, $x2, $y2, $this->allocateColor($src, $fill));
        }

        if (!$stroke->isTransparent()) {
            imagerectangle($src, $x, $y, $x2, $y2, $this->allocateColor($src, $stroke));
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

        $src = $this->requireImage();

        if ($fill !== null) {
            imagefilledellipse($src, $cx, $cy, $width, $height, $this->allocateColor($src, $fill));
        }

        if (!$stroke->isTransparent()) {
            imageellipse($src, $cx, $cy, $width, $height, $this->allocateColor($src, $stroke));
        }

        return $this;
    }

    public function line(int $x1, int $y1, int $x2, int $y2, Color $color): static
    {
        $src = $this->requireImage();
        imageline($src, $x1, $y1, $x2, $y2, $this->allocateColor($src, $color));

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

        $src = $this->requireImage();

        if ($fill !== null) {
            imagefilledarc(
                $src,
                $cx,
                $cy,
                $width,
                $height,
                $startAngle,
                $endAngle,
                $this->allocateColor($src, $fill),
                IMG_ARC_PIE,
            );
        }

        if (!$stroke->isTransparent()) {
            imagearc($src, $cx, $cy, $width, $height, $startAngle, $endAngle, $this->allocateColor($src, $stroke));
        }

        return $this;
    }

    // =========================================================================
    // MetadataInterface
    // =========================================================================

    public function getWidth(): int
    {
        return imagesx($this->requireImage());
    }

    public function getHeight(): int
    {
        return imagesy($this->requireImage());
    }

    public function getFormat(): Format
    {
        return $this->format;
    }

    public function getExif(): array
    {
        if ($this->format !== Format::JPEG) {
            throw new UnsupportedOperationException(
                sprintf('EXIF metadata is only available for JPEG; current format is "%s".', $this->format->value)
            );
        }

        return $this->exif;
    }

    public function setExif(array $data): static
    {
        throw new UnsupportedOperationException('GD does not support writing EXIF metadata.');
    }

    public function getIptc(): array
    {
        if ($this->format !== Format::JPEG) {
            throw new UnsupportedOperationException(
                sprintf('IPTC metadata is only available for JPEG; current format is "%s".', $this->format->value)
            );
        }

        return $this->iptc;
    }

    public function setIptc(array $data): static
    {
        throw new UnsupportedOperationException('GD does not support writing IPTC metadata.');
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
     * GD only handles single-frame images; always returns 1 when loaded.
     */
    public function getFrameCount(): int
    {
        return $this->isLoaded() ? 1 : 0;
    }

    /**
     * Returns a list containing the single underlying {@see GdImage}.
     *
     * @return list<GdImage>
     */
    public function getFrames(): array
    {
        return $this->image !== null ? [$this->image] : [];
    }

    public function getFrame(int $index): mixed
    {
        if ($index !== 0 || !$this->isLoaded()) {
            throw new InvalidImageArgumentException(
                sprintf('Frame index %d is out of bounds (GDDriver only supports a single frame).', $index)
            );
        }

        return $this->image;
    }

    public function addFrame(mixed $frame, int $delay, ?int $index = null): static
    {
        throw new UnsupportedOperationException(
            'GDDriver does not support multi-frame animation.'
        );
    }

    public function removeFrame(int $index): static
    {
        throw new UnsupportedOperationException(
            'GDDriver does not support multi-frame animation.'
        );
    }

    public function getFrameDelay(int $index): int
    {
        if ($index !== 0 || !$this->isLoaded()) {
            throw new InvalidImageArgumentException(
                sprintf('Frame index %d is out of bounds (GDDriver only supports a single frame).', $index)
            );
        }

        return 0;
    }

    public function setFrameDelay(int $index, int $delay): static
    {
        throw new UnsupportedOperationException(
            'GDDriver does not support multi-frame animation.'
        );
    }

    public function getLoopCount(): int
    {
        return 0;
    }

    public function setLoopCount(int $count): static
    {
        throw new UnsupportedOperationException(
            'GDDriver does not support animation loop configuration.'
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
        $formats = [Format::JPEG, Format::PNG, Format::GIF];

        if (function_exists('imagewebp')) {
            $formats[] = Format::WEBP;
        }

        if (function_exists('imageavif')) {
            $formats[] = Format::AVIF;
        }

        if (function_exists('imagebmp')) {
            $formats[] = Format::BMP;
        }

        if (function_exists('imagewbmp')) {
            $formats[] = Format::WBMP;
        }

        if (function_exists('imagexbm')) {
            $formats[] = Format::XBM;
        }

        return $formats;
    }

    // =========================================================================
    // ImageStateInterface
    // =========================================================================

    public function isLoaded(): bool
    {
        return $this->image !== null;
    }

    public function copy(): static
    {
        $src = $this->requireImage();
        $w   = imagesx($src);
        $h   = imagesy($src);

        $dst = $this->createCanvas($w, $h);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

        $clone          = new static();
        $clone->image   = $dst;
        $clone->format  = $this->format;
        $clone->quality = $this->quality;
        $clone->exif    = $this->exif;
        $clone->iptc    = $this->iptc;

        return $clone;
    }

    public function destroy(): void
    {
        $this->image = null;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Return the loaded GdImage, or throw if no image has been loaded.
     *
     * @throws ImageLoadException When no image is currently loaded.
     */
    private function requireImage(): GdImage
    {
        if ($this->image === null) {
            throw new ImageLoadException(
                'No image is loaded. Call a from*() method before applying operations.'
            );
        }

        return $this->image;
    }

    /**
     * Decode raw binary data and store the resulting GdImage in $this->image.
     *
     * @throws ImageLoadException When GD cannot decode the data.
     */
    private function loadRawData(string $data): void
    {
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new ImageLoadException('GD could not decode the provided image data.');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        $this->image = $image;
    }

    /**
     * Build a GdImage from raw binary data without modifying driver state.
     *
     * @throws ImageLoadException When GD cannot decode the data.
     */
    private function buildGdImage(string $data): GdImage
    {
        $image = @imagecreatefromstring($data);
        if ($image === false) {
            throw new ImageLoadException('GD could not decode the provided image data.');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    /**
     * Create a new true-color canvas with alpha-channel support.
     *
     * When $background is null the canvas is filled with full transparency.
     *
     * @throws ImageLoadException When GD cannot create the canvas.
     */
    private function createCanvas(int $width, int $height, ?Color $background = null): GdImage
    {
        if ($width < 1 || $height < 1) {
            throw new ImageLoadException(
                sprintf('Canvas dimensions must be positive, got %d×%d.', $width, $height)
            );
        }

        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            throw new ImageLoadException(
                sprintf('GD failed to allocate a %d×%d canvas.', $width, $height)
            );
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        if ($background !== null) {
            $bgColor = $this->allocateColor($canvas, $background);
            imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $bgColor);
        } else {
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, self::GD_ALPHA_TRANSPARENT);
            if ($transparent !== false) {
                imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $transparent);
            }
        }

        imagealphablending($canvas, true);

        return $canvas;
    }

    /**
     * Allocate a GD color index from a {@see Color} value object.
     *
     * GD uses alpha 0 (opaque) → 127 (transparent), opposite of our 0.0–1.0 range.
     *
     * @throws ImageLoadException When color allocation fails.
     */
    private function allocateColor(GdImage $image, Color $color): int
    {
        $gdAlpha = (int) round((self::ALPHA_OPAQUE - $color->alpha) * self::GD_ALPHA_TRANSPARENT);
        $r       = max(0, min(self::CHANNEL_MAX, $color->red));
        $g       = max(0, min(self::CHANNEL_MAX, $color->green));
        $b       = max(0, min(self::CHANNEL_MAX, $color->blue));
        $a       = max(0, min(self::GD_ALPHA_TRANSPARENT, $gdAlpha));
        $index   = imagecolorallocatealpha($image, $r, $g, $b, $a);

        if ($index === false) {
            throw new ImageLoadException('GD failed to allocate a color in the image palette.');
        }

        return $index;
    }

    /**
     * Detect the image format from raw binary data via getimagesizefromstring().
     *
     * @throws ImageLoadException When the format cannot be determined or is unsupported.
     */
    private function detectFormat(string $data): Format
    {
        $info = @getimagesizefromstring($data);
        if ($info === false) {
            throw new ImageLoadException('Could not determine the image format from the provided data.');
        }

        $mime = $info['mime'];

        try {
            return Format::fromMimeType($mime);
        } catch (UnsupportedFormatException $e) {
            throw new ImageLoadException(
                sprintf('Unsupported image format detected: "%s".', $mime),
                previous: $e,
            );
        }
    }

    /**
     * Encode a GdImage to a binary string in the specified format and quality.
     *
     * @throws ImageSaveException When GD encoding fails.
     */
    private function encode(GdImage $image, Format $format, ?int $quality): string
    {
        if (!ob_start()) {
            throw new ImageSaveException('Failed to start output buffering for image encoding.');
        }

        $ok   = $this->renderToBuffer($image, $format, $quality);
        $data = ob_get_clean();

        if (!$ok || $data === false || $data === '') {
            throw new ImageSaveException(
                sprintf('GD failed to encode the image as %s.', $format->value)
            );
        }

        return $data;
    }

    /**
     * Write encoded image bytes to the current output buffer.
     *
     * @return bool True on success.
     */
    private function renderToBuffer(GdImage $image, Format $format, ?int $quality): bool
    {
        return match ($format) {
            Format::JPEG => imagejpeg($image, null, $quality ?? self::DEFAULT_QUALITY_JPEG),
            Format::PNG  => imagepng($image, null, $quality ?? self::DEFAULT_QUALITY_PNG),
            Format::WEBP => imagewebp($image, null, $quality ?? self::DEFAULT_QUALITY_WEBP),
            Format::AVIF => imageavif($image, null, $quality ?? self::DEFAULT_QUALITY_AVIF),
            Format::GIF  => imagegif($image),
            Format::BMP  => imagebmp($image, null, $quality === null || $quality !== 0),
            default      => false,
        };
    }

    // ─── Resize helpers ───────────────────────────────────────────────────────

    private function resizeFill(
        GdImage $src,
        int $srcW,
        int $srcH,
        int $dstW,
        int $dstH,
        bool $upscale,
    ): GdImage {
        if (!$upscale) {
            $dstW = min($dstW, $srcW);
            $dstH = min($dstH, $srcH);
        }

        $dst = $this->createCanvas($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

        return $dst;
    }

    private function resizeCover(
        GdImage $src,
        int $srcW,
        int $srcH,
        int $dstW,
        int $dstH,
        Position $position,
        bool $upscale,
    ): GdImage {
        $ratio = max($dstW / $srcW, $dstH / $srcH);

        if (!$upscale) {
            $ratio = min($ratio, 1.0);
        }

        // Size of the scaled source in destination pixels
        $scaledW = (int) round($srcW * $ratio);
        $scaledH = (int) round($srcH * $ratio);

        // Anchor: how far into the scaled image the crop window starts
        [$cropX, $cropY] = $position->toOffset($scaledW, $scaledH, $dstW, $dstH);

        // Back-project the crop offset into source-image coordinates
        $srcCropX = (int) round($cropX / $ratio);
        $srcCropY = (int) round($cropY / $ratio);
        $srcRegW  = (int) round($dstW / $ratio);
        $srcRegH  = (int) round($dstH / $ratio);

        // Clamp to avoid reading outside the source image
        $srcCropX = max(0, min($srcCropX, $srcW - 1));
        $srcCropY = max(0, min($srcCropY, $srcH - 1));
        $srcRegW  = min($srcRegW, $srcW - $srcCropX);
        $srcRegH  = min($srcRegH, $srcH - $srcCropY);

        $dst = $this->createCanvas($dstW, $dstH);
        imagecopyresampled($dst, $src, 0, 0, $srcCropX, $srcCropY, $dstW, $dstH, $srcRegW, $srcRegH);

        return $dst;
    }

    private function resizeContain(
        GdImage $src,
        int $srcW,
        int $srcH,
        int $dstW,
        int $dstH,
        ?Color $background,
        bool $upscale,
    ): GdImage {
        $ratio = min($dstW / $srcW, $dstH / $srcH);

        if (!$upscale) {
            $ratio = min($ratio, 1.0);
        }

        $fitW    = (int) round($srcW * $ratio);
        $fitH    = (int) round($srcH * $ratio);
        $offsetX = (int) (($dstW - $fitW) / self::CENTER_DIVISOR);
        $offsetY = (int) (($dstH - $fitH) / self::CENTER_DIVISOR);

        $canvas = $this->createCanvas($dstW, $dstH, $background);
        imagecopyresampled($canvas, $src, $offsetX, $offsetY, 0, 0, $fitW, $fitH, $srcW, $srcH);

        return $canvas;
    }
}
