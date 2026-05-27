<?php

/**
 * gadwinn/image - Visual tests
 *
 * Open this file directly in your browser via a local PHP server.
 * No build step, no export pipeline — just a regular PHP page.
 *
 *   php -S localhost:8000 -t examples/
 *   Then open: http://localhost:8000/visual.php
 *
 * ─── To switch driver, change the constant below ────────────────────────────
 *   const DRIVER = GDDriver::class;
 *   const DRIVER = ImagickDriver::class;
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use G\Image\Color\Color;
use G\Image\Driver\DriverInterface;
use G\Image\Driver\GDDriver;
use G\Image\Enum\Fit;
use G\Image\Enum\Flip;
use G\Image\Enum\Format;
use G\Image\Enum\Position;
use G\Image\Factory\ImageFactory;

// ─── Change this line to swap the driver ─────────────────────────────────────
const DRIVER = GDDriver::class;

// ─── Source image (WebP, 264x200, transparent background) ────────────────────
const SOURCE_B64 = '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';

$srcBinary = base64_decode(SOURCE_B64);

// ─── Factory & driver instance helpers ───────────────────────────────────────

$factory = new ImageFactory();

/**
 * Create a fresh driver instance loaded with the source binary.
 *
 * @param string $src Raw image binary.
 */
function makeDriver(string $src): DriverInterface
{
    global $factory;
    /** @var DriverInterface $d */
    $d = $factory->create(DRIVER);
    $d->fromString($src);
    return $d;
}

// ─── Rendering helpers ────────────────────────────────────────────────────────

/**
 * Return an inline base64 <img> tag.
 *
 * @param string $binary Raw image binary.
 * @param string $mime   MIME type.
 */
function b64img(string $binary, string $mime = 'image/png'): string
{
    return '<img src="data:' . $mime . ';base64,' . base64_encode($binary) . '">';
}

function section(string $label): void
{
    echo '<tr class="section-head"><td colspan="2">' . htmlspecialchars($label) . '</td></tr>' . "\n";
}

/**
 * Apply an operation and output a table row.
 * If the operation throws, displays the error message inline instead of the image.
 *
 * @param string   $fn   Method signature shown as code label.
 * @param string   $desc Short description.
 * @param string   $src  Raw source binary.
 * @param callable $op   fn(DriverInterface): void
 */
function row(string $fn, string $desc, string $src, callable $op): void
{
    $result = null;
    $error  = null;

    try {
        $d = makeDriver($src);
        $op($d);
        $result = $d->toPng()->toString();
    } catch (Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }

    echo '<tr>';
    echo '<td>'
       . '<div class="fn">' . htmlspecialchars($fn) . '</div>'
       . '<div class="desc">' . htmlspecialchars($desc) . '</div>'
       . '</td>';
    echo '<td><div class="result-cell"><span class="arrow">&#8594;</span>';

    if ($result !== null) {
        echo '<div class="checker">' . b64img($result) . '</div>';
    } else {
        echo '<div class="error">' . htmlspecialchars((string) $error) . '</div>';
    }

    echo '</div></td>';
    echo '</tr>' . "\n";
}

// ─── HTML ─────────────────────────────────────────────────────────────────────

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>gadwinn/image - Visual tests</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: system-ui, -apple-system, sans-serif;
    background: #0f1117;
    color: #e2e8f0;
    padding: 2rem 1.5rem 5rem;
    max-width: 960px;
    margin: auto;
  }
  h1 { font-size: 1.3rem; font-weight: 700; color: #f7fafc; }
  .sub { font-size: 0.78rem; color: #718096; margin: 0.3rem 0 2rem; }
  .driver-badge {
    display: inline-block;
    background: #2d3748;
    border: 1px solid #4a5568;
    border-radius: 4px;
    font-family: ui-monospace, monospace;
    font-size: 0.75rem;
    color: #90cdf4;
    padding: 0.15rem 0.5rem;
    margin-left: 0.6rem;
    vertical-align: middle;
  }
  .source-block {
    display: flex;
    align-items: center;
    gap: 1.5rem;
    background: #1a202c;
    border: 1px solid #2d3748;
    border-radius: 10px;
    padding: 1.25rem 1.5rem;
    margin-bottom: 2.5rem;
  }
  .source-block h2 {
    font-size: 0.8rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #718096;
    margin-bottom: 0.4rem;
  }
  .source-block p { font-size: 0.78rem; color: #a0aec0; }
  .checker {
    background:
      repeating-conic-gradient(#262d3d 0% 25%, #1a202c 0% 50%)
      0 0 / 12px 12px;
    border-radius: 4px;
    padding: 6px;
    display: inline-flex;
    flex-shrink: 0;
  }
  .checker img { display: block; max-width: 396px; max-height: 300px; border-radius: 2px; }
  table { width: 100%; border-collapse: separate; border-spacing: 0 6px; }
  td {
    padding: 0.7rem 1rem;
    border-top: 1px solid #2d3748;
    border-bottom: 1px solid #2d3748;
    background: #1a202c;
    vertical-align: middle;
  }
  td:first-child {
    border-left: 1px solid #2d3748;
    border-radius: 8px 0 0 8px;
    width: 42%;
  }
  td:last-child { border-right: 1px solid #2d3748; border-radius: 0 8px 8px 0; }
  .fn { font-family: ui-monospace, monospace; font-size: 0.82rem; font-weight: 600; color: #90cdf4; }
  .desc { font-size: 0.72rem; color: #718096; margin-top: 0.2rem; }
  .result-cell { display: flex; align-items: center; gap: 0.75rem; }
  .arrow { color: #4a5568; font-size: 1.1rem; }
  .error {
    font-family: ui-monospace, monospace;
    font-size: 0.72rem;
    color: #fc8181;
    background: #2d1515;
    border: 1px solid #742a2a;
    border-radius: 4px;
    padding: 0.4rem 0.6rem;
    max-width: 440px;
  }
  .section-head td {
    background: transparent;
    border: none;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: #4a5568;
    padding: 1.25rem 0 0.3rem;
  }
</style>
</head>
<body>

<h1>gadwinn/image &mdash; Visual tests <span class="driver-badge"><?php echo htmlspecialchars(DRIVER); ?></span></h1>
<p class="sub">WebP source image, 264&times;200px, transparent background &mdash; applied as-is to every operation</p>

<div class="source-block">
  <div class="checker"><?php echo b64img($srcBinary, 'image/webp'); ?></div>
  <div>
    <h2>Source image</h2>
    <p>WebP &mdash; 264 &times; 200 &mdash; transparent background</p>
    <p style="margin-top:0.5rem;font-size:0.72rem;color:#4a5568">Used as input for every operation below.</p>
  </div>
</div>

<table>
<?php

// ─── Resize ───────────────────────────────────────────────────────────────────
section('Resize');
row('resize(120, 80, FILL)',     'Exact target size, aspect ratio ignored',           $srcBinary, static fn (DriverInterface $d) => $d->resize(120, 80,  Fit::FILL));
row('resize(150, 150, COVER)',   'Fills target, excess cropped from center',          $srcBinary, static fn (DriverInterface $d) => $d->resize(150, 150, Fit::COVER,   Position::MIDDLE_CENTER));
row('resize(150, 150, CONTAIN)', 'Fits inside target, grey padding fills remainder',  $srcBinary, static fn (DriverInterface $d) => $d->resize(150, 150, Fit::CONTAIN, Position::MIDDLE_CENTER, Color::fromHex('#cccccc')));
row('resize(150, null)',         'Width fixed, height computed from aspect ratio',    $srcBinary, static fn (DriverInterface $d) => $d->resize(150, null, Fit::FILL));
row('resize(null, 80)',          'Height fixed, width computed from aspect ratio',    $srcBinary, static fn (DriverInterface $d) => $d->resize(null, 80,  Fit::FILL));

// ─── Scale ────────────────────────────────────────────────────────────────────
section('Scale');
row('scale(40)',  'Scales to 40% of original size',  $srcBinary, static fn (DriverInterface $d) => $d->scale(40));
row('scale(150)', 'Scales to 150% of original size', $srcBinary, static fn (DriverInterface $d) => $d->scale(150));

// ─── Crop & Trim ──────────────────────────────────────────────────────────────
section('Crop & Trim');
row('crop(180, 120, 40, 30)', 'Extracts a 180x120 region at x=40, y=30',          $srcBinary, static fn (DriverInterface $d) => $d->crop(180, 120, 40, 30));
row('trim(10, TOP_LEFT)',     'Removes uniform border sampled from the top-left',  $srcBinary, static fn (DriverInterface $d) => $d->trim(10, Position::TOP_LEFT));

// ─── Transform ────────────────────────────────────────────────────────────────
section('Transform');
row('rotate(90, white)', 'Rotates 90deg clockwise, white background',  $srcBinary, static fn (DriverInterface $d) => $d->rotate(90.0, Color::fromHex('#ffffff')));
row('rotate(45, white)', 'Rotates 45deg clockwise, white background',  $srcBinary, static fn (DriverInterface $d) => $d->rotate(45.0, Color::fromHex('#ffffff')));
row('flip(HORIZONTAL)',  'Flips left to right',                        $srcBinary, static fn (DriverInterface $d) => $d->flip(Flip::HORIZONTAL));
row('flip(VERTICAL)',    'Flips top to bottom',                        $srcBinary, static fn (DriverInterface $d) => $d->flip(Flip::VERTICAL));
row('flip(BOTH)',        'Flips both axes',                            $srcBinary, static fn (DriverInterface $d) => $d->flip(Flip::BOTH));

// ─── Filters ──────────────────────────────────────────────────────────────────
section('Filters');
row('blur(3)',         'Gaussian blur, 3 passes',      $srcBinary, static fn (DriverInterface $d) => $d->blur(3));
row('sharpen(2)',      'Sharpening kernel, 2 passes',  $srcBinary, static fn (DriverInterface $d) => $d->sharpen(2));
row('brightness(60)',  'Increases brightness by +60',  $srcBinary, static fn (DriverInterface $d) => $d->brightness(60));
row('brightness(-60)', 'Decreases brightness by -60',  $srcBinary, static fn (DriverInterface $d) => $d->brightness(-60));
row('contrast(50)',    'Increases contrast by +50',    $srcBinary, static fn (DriverInterface $d) => $d->contrast(50));
row('contrast(-50)',   'Decreases contrast by -50',    $srcBinary, static fn (DriverInterface $d) => $d->contrast(-50));
row('grayscale()',     'Converts to greyscale',        $srcBinary, static fn (DriverInterface $d) => $d->grayscale());
row('negate()',        'Inverts all colour channels',  $srcBinary, static fn (DriverInterface $d) => $d->negate());
row('sepia()',         'Applies warm sepia tone',      $srcBinary, static fn (DriverInterface $d) => $d->sepia());
row('emboss()',        'Emboss relief effect',         $srcBinary, static fn (DriverInterface $d) => $d->emboss());

// ─── Format conversion ────────────────────────────────────────────────────────
section('Format conversion');
row('toJpeg(30)', 'JPEG quality 30 (visible artefacts)', $srcBinary, static fn (DriverInterface $d) => $d->toJpeg(30));
row('toGif()',    '256-colour GIF palette reduction',     $srcBinary, static fn (DriverInterface $d) => $d->toGif());

$probe = $factory->create(DRIVER);
if ($probe->supportsFormat(Format::WEBP)) {
    row('toWebp(60)', 'WebP quality 60', $srcBinary, static fn (DriverInterface $d) => $d->toWebp(60));
}
if ($probe->supportsFormat(Format::AVIF)) {
    row('toAvif(40)', 'AVIF quality 40', $srcBinary, static fn (DriverInterface $d) => $d->toAvif(40));
}

// ─── Composition ─────────────────────────────────────────────────────────────
section('Composition');
row(
    'rect(10, 10, 120, 80, red, pink)',
    'Filled rectangle with coloured stroke',
    $srcBinary,
    static fn (DriverInterface $d) => $d->rect(10, 10, 120, 80, Color::fromRgba(220, 30, 30, 1.0), Color::fromRgba(255, 200, 200, 0.5)),
);
row(
    'ellipse(132, 100, 200, 140, blue, light-blue)',
    'Filled ellipse centered on the image',
    $srcBinary,
    static fn (DriverInterface $d) => $d->ellipse(132, 100, 200, 140, Color::fromRgba(0, 80, 200, 1.0), Color::fromRgba(180, 210, 255, 0.4)),
);
row(
    'line(0, 0, 263, 199, yellow)',
    'Diagonal line across the full image',
    $srcBinary,
    static fn (DriverInterface $d) => $d->line(0, 0, 263, 199, Color::fromRgba(255, 220, 0, 1.0)),
);
row(
    'arc(200, 40, 100, 80, 0, 270)',
    'Pie slice arc in the top-right area',
    $srcBinary,
    static fn (DriverInterface $d) => $d->arc(200, 40, 100, 80, 0, 270, Color::fromRgba(255, 255, 255, 1.0), Color::fromRgba(255, 255, 255, 0.3)),
);
row(
    'watermark(src, BOTTOM_RIGHT, 65%)',
    'Overlays a scaled-down copy of the source as watermark',
    $srcBinary,
    static function (DriverInterface $d) use ($srcBinary): void {
        global $factory;
        /** @var DriverInterface $wm */
        $wm = $factory->create(DRIVER);
        $wm->fromString($srcBinary)->resize(70, 53, Fit::FILL);
        $d->watermark($wm, Position::BOTTOM_RIGHT, 65);
    },
);

// ─── Full pipeline ────────────────────────────────────────────────────────────
section('Full pipeline');
row(
    'COVER resize + sepia + ellipse + watermark',
    'Chains multiple operations on the same driver instance',
    $srcBinary,
    static function (DriverInterface $d) use ($srcBinary): void {
        global $factory;
        $d->resize(264, 200, Fit::COVER, Position::MIDDLE_CENTER)
          ->sepia()
          ->ellipse(132, 100, 250, 190, Color::fromRgba(255, 255, 255, 0.35), Color::fromRgba(0, 0, 0, 0.0));
        /** @var DriverInterface $wm */
        $wm = $factory->create(DRIVER);
        $wm->fromString($srcBinary)->resize(60, 46, Fit::FILL);
        $d->watermark($wm, Position::BOTTOM_RIGHT, 70);
    },
);

?>
</table>
</body>
</html>
