# gadwinn/image

A fluent PHP image-processing library built on top of GD or Imagick.
A single, consistent API regardless of the underlying driver.

## Requirements

| Dependency | Version |
|---|---|
| PHP | `>=8.3` |
| `psr/http-message` | `^2.0` |
| `ext-gd` | Any (for `GDDriver`) |
| `ext-imagick` | Any (for `ImagickDriver`) |

At least one driver extension must be installed at runtime.
Both can coexist; choose per use-case.

## Installation

```bash
composer require gadwinn/image
```

---

## Quick Start

```php
use G\Image\Image;
use G\Image\Driver\GDDriver;

Image::create(GDDriver::class)
    ->fromFile('/var/www/uploads/photo.jpg')
    ->resize(1200, 800)
    ->toWebp(85)
    ->save('/var/www/public/photo.webp');
```

---

## Drivers

Both drivers implement the exact same `DriverInterface`.
Swap by changing the class passed to the factory.

```php
use G\Image\Driver\GDDriver;
use G\Image\Driver\ImagickDriver;
use G\Image\Factory\ImageFactory;

// Via the static facade (simplest)
$driver = Image::create(GDDriver::class);
$driver = Image::create(ImagickDriver::class);

// Via the factory directly (IDE type-narrowing to the concrete class)
$factory = new ImageFactory();
$gd      = $factory->create(GDDriver::class);     // typed as GDDriver
$imagick = $factory->create(ImagickDriver::class); // typed as ImagickDriver

// Injecting a custom factory (DI container / test double)
Image::setFactory($myCustomFactory);
$driver = Image::create(GDDriver::class); // delegates to $myCustomFactory

// Reset to the default factory
Image::setFactory(null);

// First available driver — no extension_loaded() check needed by the caller
$driver = Image::createFirstAvailable([GDDriver::class, ImagickDriver::class]);
// throws UnsupportedOperationException when none of the candidates is available
```

### Format support matrix

| Format | GDDriver | ImagickDriver |
|--------|:--------:|:-------------:|
| JPEG   | ✓ | ✓ |
| PNG    | ✓ | ✓ |
| WebP   | ✓* | ✓ |
| AVIF   | ✓* | ✓ |
| GIF    | ✓ | ✓ |
| BMP    | ✓* | ✓ |
| TIFF   | ✗ | ✓ |
| HEIC   | ✗ | ✓** |
| WBMP   | ✓* | ✗ |
| XBM    | ✓* | ✗ |

`*` Availability depends on the GD build. Use `supportsFormat()` to probe at runtime.  
`**` Requires ImageMagick compiled with the HEIC delegate.

---

## Loading Images

```php
use G\Image\Image;
use G\Image\Driver\GDDriver;
use G\Image\Color\Color;

$driver = Image::create(GDDriver::class);

// A blank canvas — null background is fully transparent (both drivers support alpha);
// getFormat() on a blank canvas is Format::PNG until a to*() call changes it
$driver->fromBlank(400, 300);
$driver->fromBlank(400, 300, Color::fromHex('#1a1a2e'));

// From a filesystem path
$driver->fromFile('/path/to/image.jpg');

// From a URL
$driver->fromUrl('https://example.com/photo.jpg');

// From a raw binary string
$driver->fromString(file_get_contents('/path/to/image.png'));

// From a base64-encoded string (no data-URI prefix)
$driver->fromBase64($base64String);

// From a driver-native blob (GdImage for GDDriver, Imagick for ImagickDriver)
// NOTE: when loading a GdImage directly, the output format is not automatically
// detected. Call a conversion method (e.g. toPng()) before any output call.
$driver->fromBlob($nativeBlob);

// From a PHP stream resource
$resource = fopen('/path/to/image.jpg', 'rb');
$driver->fromResource($resource);

// From a PSR-7 StreamInterface
$driver->fromStream($psrStream);
```

All `from*` methods return `static` for fluent chaining.

### Guarding against oversized uploads

`limitPixels()` is opt-in and off by default — no hardcoded ceiling, so
legitimately huge images keep working for consumers that don't call it.
When set, it inspects the image *header* before decoding (never the fully
decoded image), so the check can actually prevent the memory spike instead
of running after it already happened.

```php
use G\Image\Exception\ImageLimitExceededException;

try {
    Image::create(GDDriver::class)
        ->limitPixels(200_000_000) // width × height, checked before decode
        ->fromFile($upload);
} catch (ImageLimitExceededException $e) {
    // rejected based on header inspection alone — nothing was decoded
}
```

`fromResource()`/`fromStream()` buffer their input before decoding, so the
same header check applies there too. The only case it cannot cover is
`fromBlob()` given an already-decoded native object (`GdImage`/`Imagick`) —
the memory is already allocated by the caller at that point; the limit is
still enforced against the object's existing dimensions, it just can't
prevent an allocation that already happened.

---

## Resizing

```php
use G\Image\Enum\Fit;
use G\Image\Enum\Position;
use G\Image\Color\Color;

// Fit within 800×600 — preserves aspect ratio, adds letterbox fill
$driver->resize(800, 600);

// Cover 800×600 — preserves aspect ratio, crops the excess
$driver->resize(800, 600, Fit::COVER, Position::MIDDLE_CENTER);

// Stretch to exactly 800×600 — ignores aspect ratio
$driver->resize(800, 600, Fit::FILL);

// Infer height from width (proportional)
$driver->resize(1200, null);

// Infer width from height
$driver->resize(null, 800);

// Custom letterbox fill color (CONTAIN mode)
$driver->resize(800, 600, Fit::CONTAIN, Position::MIDDLE_CENTER, Color::fromHex('#1a1a2e'));

// Prevent upscaling — never enlarge beyond original dimensions
$driver->resize(4000, null, Fit::CONTAIN, Position::MIDDLE_CENTER, null, upscale: false);

// Scale proportionally by percentage (50 = halve, 200 = double)
$driver->scale(50);
```

### Fit modes

| `Fit` | Behaviour |
|---|---|
| `CONTAIN` | Entire image fits inside the box; empty space filled with `$background`. |
| `COVER` | Box fully covered; excess pixels cropped using `$position` as the anchor. |
| `FILL` | Image stretched to exactly match the box, aspect ratio ignored. |

### Position anchors

`TOP_LEFT` · `TOP_CENTER` · `TOP_RIGHT`  
`MIDDLE_LEFT` · `MIDDLE_CENTER` · `MIDDLE_RIGHT`  
`BOTTOM_LEFT` · `BOTTOM_CENTER` · `BOTTOM_RIGHT`

---

## Cropping

```php
// Crop a 400×300 region starting at (100, 50)
$driver->crop(400, 300, 100, 50);

// Auto-trim uniform-colour borders (threshold 0–255, corner pixel as reference)
$driver->trim();
$driver->trim(threshold: 15, corner: Position::TOP_LEFT);
```

---

## Transforms

```php
use G\Image\Enum\Flip;

// Rotate clockwise (any angle; canvas expands for non-90° values)
$driver->rotate(90.0);
$driver->rotate(45.0, Color::fromHex('#ffffff')); // white fill on revealed corners

// Flip
$driver->flip(Flip::HORIZONTAL);
$driver->flip(Flip::VERTICAL);
$driver->flip(Flip::BOTH);
```

---

## Filters

```php
// Blur (amount > 0, driver-defined scale)
$driver->blur();
$driver->blur(5);

// Sharpen
$driver->sharpen();
$driver->sharpen(3);

// Brightness / Contrast — range: -100 to +100, 0 is a no-op
$driver->brightness(20);
$driver->contrast(-10);

// Pixel effects (no parameters)
$driver->grayscale();
$driver->negate();
$driver->sepia();
$driver->emboss();
```

---

## Format Conversion

Format methods set the target encoder and optionally a quality parameter.
Encoding happens lazily on the first output call (`toString()`, `save()`, etc.).

```php
// Named shortcuts
$driver->toJpeg(90);       // quality 1–100 (null = driver default ~85)
$driver->toPng(6);         // compression 0–9 (null = driver default ~6)
$driver->toWebp(80);       // quality 1–100
$driver->toAvif(75);       // quality 1–100
$driver->toGif();          // no quality parameter
$driver->toBmp();          // null = driver default (uncompressed)
$driver->toBmp(0);         // 0 = uncompressed
$driver->toBmp(1);         // 1 = RLE compressed
$driver->toTiff();         // ImagickDriver only
$driver->toHeic(85);       // ImagickDriver only, requires HEIC delegate

// Generic (useful when the format is a variable)
use G\Image\Enum\Format;

$driver->toFormat(Format::WEBP, 80);
$driver->toFormat(Format::PNG);
```

---

## Output

```php
// Save to the filesystem (overwrites if file exists)
$driver->save('/var/www/public/output.webp');

// Get encoded binary string
$binary = $driver->toString();

// Get base64-encoded string (no data-URI prefix)
$b64 = $driver->toBase64();

// Get the driver-native object (GdImage for GDDriver, Imagick for ImagickDriver)
$blob = $driver->toBlob();

// Write encoded bytes to an open PHP resource
$resource = fopen('/tmp/out.jpg', 'wb');
$driver->toResource($resource);

// Write encoded bytes to a PSR-7 stream
$driver->toStream($psrStream);
```

All output methods return `static` except `toString()`, `toBase64()`, and `toBlob()`.

---

## Composition & Drawing

```php
use G\Image\Color\Color;
use G\Image\Enum\Position;

// Overlay a watermark (any loaded DriverInterface instance)
$watermark = Image::create(GDDriver::class)->fromFile('/path/to/logo.png');
$driver->watermark($watermark, Position::BOTTOM_RIGHT);
$driver->watermark($watermark, Position::BOTTOM_RIGHT, opacity: 60); // 0–100

// Text — $x/$y is the position of the $anchor point of the text box (TOP_LEFT by
// default, matching rect()'s own $x/$y convention), not a baseline origin
$driver->text(
    content: 'Hello World',
    x: 20,
    y: 40,
    fontPath: '/fonts/Roboto-Bold.ttf',
    fontSize: 24,
    color: Color::fromRgba(255, 255, 255, 0.9),
    angle: 0.0,
);

// Centred text on a 400×300 canvas — anchor the middle of the text box to the
// canvas's own center point
$driver->text(
    content: 'Hello World',
    x: 200,
    y: 150,
    fontPath: '/fonts/Roboto-Bold.ttf',
    fontSize: 24,
    color: Color::fromHex('#ffffff'),
    anchor: Position::MIDDLE_CENTER,
);

// No font file needed — GDDriver has a built-in bitmap font (ImagickDriver
// throws UnsupportedOperationException; probe with supportsOperation('text.builtinFont')).
// Fixed sizes only (fontSize snaps to GD's nearest of 5), ISO-8859-1 range only
// (throws InvalidImageArgumentException on unrepresentable characters), no rotation.
$driver->text(
    content: 'Thumbnail',
    x: 10,
    y: 10,
    fontPath: null,
    fontSize: 16,
    color: Color::fromHex('#000000'),
);

// Rectangle (stroke + optional fill)
$driver->rect(
    x: 10, y: 10, width: 200, height: 100,
    stroke: Color::fromHex('#ff0000'),
    fill: Color::fromRgba(255, 0, 0, 0.3),
);

// Ellipse / circle
$driver->ellipse(
    cx: 100, cy: 100, width: 80, height: 80,
    stroke: Color::fromHex('#0000ff'),
);

// Line
$driver->line(x1: 0, y1: 0, x2: 400, y2: 300, color: Color::fromHex('#00ff00'));

// Arc (angles in degrees, 0 = 3 o'clock, clockwise)
$driver->arc(
    cx: 200, cy: 200, width: 150, height: 150,
    startAngle: 0, endAngle: 90,
    stroke: Color::fromHex('#ff6600'),
    fill: Color::fromRgba(255, 102, 0, 0.2),
);
```

---

## Metadata

```php
// Dimensions and format
$width  = $driver->getWidth();   // int (px)
$height = $driver->getHeight();  // int (px)
$format = $driver->getFormat();  // Format enum

// EXIF — JPEG only; throws UnsupportedOperationException for other formats
$exif = $driver->getExif(); // array<string, mixed>

// IPTC — throws UnsupportedOperationException when unsupported
$iptc = $driver->getIptc(); // array<string, mixed>

// Strip all embedded metadata before publishing
$driver->stripMetadata(); // removes EXIF, IPTC, XMP, ICC profiles
```

---

## Animation (ImagickDriver)

`GDDriver` throws `UnsupportedOperationException` for all mutation methods below.

```php
use G\Image\Driver\ImagickDriver;
use G\Image\Factory\ImageFactory;

$anim = (new ImageFactory())->create(ImagickDriver::class)
    ->fromFile('/path/to/animation.gif');

$frameCount = $anim->getFrameCount();             // int (1 for static images)
$frames     = $anim->getFrames();                 // list<mixed> — Imagick objects
$frame      = $anim->getFrame(0);                 // mixed — frame at index 0

$delay      = $anim->getFrameDelay(0);            // int — milliseconds
$anim->setFrameDelay(index: 0, delay: 100);       // set frame 0 to 100 ms

$loops = $anim->getLoopCount();                   // int (0 = infinite)
$anim->setLoopCount(0);                           // loop forever

// Read only on GDDriver (getFrameCount always returns 1)
```

---

## Capability Introspection

```php
use G\Image\Enum\Format;

// Check format support before attempting conversion
if ($driver->supportsFormat(Format::AVIF)) {
    $driver->toAvif(75);
} else {
    $driver->toWebp(75);
}

// Check operation support
if ($driver->supportsOperation('toHeic')) {
    $driver->toHeic(85);
}

// List all formats the driver can read and write
/** @var list<Format> $formats */
$formats = $driver->getSupportedFormats();
```

### Operation capability table (driver-specific asymmetries)

Most operations are supported identically by both drivers. The exceptions:

| Operation key         | GDDriver | ImagickDriver | Notes |
|------------------------|:--------:|:-------------:|---|
| `text.builtinFont`     | ✓ | ✗ | GD: `text()` with `fontPath: null` uses a built-in bitmap font. Imagick throws `UnsupportedOperationException` when `fontPath` is null — a TTF/OTF file is always required. |
| `toTiff`, `toHeic`     | ✗ | ✓ | GD has no TIFF/HEIC encoder. |

Static driver availability (before an instance exists) is a separate check:

```php
GDDriver::isAvailable();      // bool — extension_loaded('gd')
ImagickDriver::isAvailable(); // bool — extension_loaded('imagick')
```

---

## Pipeline Branching

`copy()` produces a deep clone with no shared internal state,
allowing a single source to feed multiple output pipelines.

```php
$source = Image::create(GDDriver::class)->fromFile('/path/to/photo.jpg');

$thumb = $source->copy()
    ->resize(300, 200, Fit::COVER)
    ->toWebp(70)
    ->save('/public/photo_thumb.webp');

$full = $source
    ->resize(1920, null)
    ->stripMetadata()
    ->toWebp(85)
    ->save('/public/photo_full.webp');
```

---

## State & Lifecycle

```php
$driver->isLoaded(); // bool — true after any fromXxx() call

// Release the underlying GdImage / Imagick resource
$driver->destroy();
// Instance must not be used after destroy()
```

---

## Color API

`Color` is an immutable value object (RGBA, 0–255 channels, alpha 0.0–1.0).

```php
use G\Image\Color\Color;

// Hex (3, 4, 6 or 8 digits — leading # optional, case-insensitive)
Color::fromHex('#ff6600');
Color::fromHex('ff6600');
Color::fromHex('#f60');
Color::fromHex('#ff6600cc'); // with alpha (cc = 80 % opaque)

// RGB / RGBA
Color::fromRgb(255, 102, 0);
Color::fromRgba(255, 102, 0, 0.5);

// HSL (CSS convention: hue 0–360, saturation & lightness 0–100)
Color::fromHsl(24.0, 100.0, 50.0);
Color::fromHsla(24.0, 100.0, 50.0, 0.8);

// OKLCH (CSS Color Level 4: perceptually uniform)
Color::fromOklch(0.65, 0.20, 40.0);
Color::fromOklcha(0.65, 0.20, 40.0, 0.9);

// CSS string / array auto-detect
Color::from('#ff6600');
Color::from('rgba(255, 102, 0, 0.5)');
Color::from([255, 102, 0]);
Color::from([255, 102, 0, 0.5]);

// Transparent black
Color::transparent();

// Property access (readonly)
$color->red;   // int 0–255
$color->green; // int 0–255
$color->blue;  // int 0–255
$color->alpha; // float 0.0–1.0
```

---

## Exception Hierarchy

All exceptions implement `ImageExceptionInterface` — catch the root to handle any failure.

```
ImageExceptionInterface
├── ImageException (RuntimeException)          — environmental failures
│   ├── ImageLoadException                     — cannot load source
│   ├── ImageSaveException                     — cannot encode / write
│   ├── ImageLimitExceededException            — decoded image would exceed limitPixels()
│   ├── UnsupportedFormatException             — format not available in current driver
│   └── UnsupportedOperationException          — operation not available in current driver
└── ImageLogicException (LogicException)       — programming errors
    ├── InvalidColorException                  — malformed colour value
    └── InvalidImageArgumentException          — invalid argument (dimension, quality, …)
```

```php
use G\Image\Exception\ImageExceptionInterface;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\UnsupportedFormatException;

try {
    Image::create(GDDriver::class)
        ->fromFile('/missing.jpg')
        ->toAvif(75)
        ->save('/output.avif');
} catch (ImageLoadException $e) {
    // file not found, corrupt data, etc.
} catch (UnsupportedFormatException $e) {
    // AVIF not compiled into this GD build
} catch (ImageExceptionInterface $e) {
    // any other library exception
}
```
