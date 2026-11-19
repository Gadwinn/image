<?php

declare(strict_types=1);

namespace G\Image\Tests\Driver;

use G\Image\Color\Color;
use G\Image\Driver\ImagickDriver;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

#[CoversClass(ImagickDriver::class)]
#[RequiresPhpExtension('imagick')]
final class ImagickDriverTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a minimal in-memory PNG binary string (uses GD for portability).
     */
    private function makePng(int $width = 20, int $height = 20): string
    {
        $img = imagecreatetruecolor($width, $height);
        assert($img !== false);

        $color = imagecolorallocate($img, 200, 100, 50);
        assert($color !== false);

        imagefill($img, 0, 0, $color);
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    /**
     * Build a minimal in-memory JPEG binary string (uses GD for portability).
     */
    private function makeJpeg(int $width = 20, int $height = 20): string
    {
        $img = imagecreatetruecolor($width, $height);
        assert($img !== false);

        $color = imagecolorallocate($img, 200, 100, 50);
        assert($color !== false);

        imagefill($img, 0, 0, $color);
        ob_start();
        imagejpeg($img, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Return a loaded ImagickDriver instance backed by a 20×20 PNG.
     */
    private function loaded(int $width = 20, int $height = 20): ImagickDriver
    {
        return (new ImagickDriver())->fromString($this->makePng($width, $height));
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    public function testInstantiationSucceedsWhenImagickIsLoaded(): void
    {
        $driver = new ImagickDriver();
        self::assertFalse($driver->isLoaded());
    }

    // =========================================================================
    // InputInterface
    // =========================================================================

    public function testFromStringLoadsPng(): void
    {
        $driver = (new ImagickDriver())->fromString($this->makePng());
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testFromStringLoadsJpeg(): void
    {
        $driver = (new ImagickDriver())->fromString($this->makeJpeg());
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::JPEG, $driver->getFormat());
    }

    public function testFromStringThrowsOnInvalidData(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromString('not image data at all');
    }

    public function testFromBase64LoadsImage(): void
    {
        $driver = (new ImagickDriver())->fromBase64(base64_encode($this->makePng()));
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testFromBase64ThrowsOnInvalidPayload(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromBase64('!!!invalid base64!!!');
    }

    public function testFromBlobAcceptsImagickInstance(): void
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($this->makePng());

        $driver = (new ImagickDriver())->fromBlob($imagick);
        self::assertTrue($driver->isLoaded());
    }

    public function testFromBlobAcceptsBinaryString(): void
    {
        $driver = (new ImagickDriver())->fromBlob($this->makePng());
        self::assertTrue($driver->isLoaded());
    }

    public function testFromBlobThrowsOnUnsupportedType(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromBlob(42);
    }

    public function testFromResourceLoadsImage(): void
    {
        $tmp = tmpfile();
        assert($tmp !== false);

        fwrite($tmp, $this->makePng());
        rewind($tmp);

        $driver = (new ImagickDriver())->fromResource($tmp);
        self::assertTrue($driver->isLoaded());

        fclose($tmp);
    }

    public function testFromResourceThrowsOnNonResource(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromResource('not a resource');
    }

    public function testFromStreamLoadsImage(): void
    {
        $png    = $this->makePng();
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($png);

        $driver = (new ImagickDriver())->fromStream($stream);
        self::assertTrue($driver->isLoaded());
    }

    public function testFromStreamThrowsOnEmptyStream(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn('');

        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromStream($stream);
    }

    public function testFromFileLoadsImageFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imagick_test') . '.png';
        file_put_contents($path, $this->makePng());

        try {
            $driver = (new ImagickDriver())->fromFile($path);
            self::assertTrue($driver->isLoaded());
            self::assertSame(Format::PNG, $driver->getFormat());
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromFile('/nonexistent/path/image.png');
    }

    // =========================================================================
    // OutputInterface
    // =========================================================================

    public function testToStringReturnsBinaryData(): void
    {
        $data = $this->loaded()->toPng()->toString();
        self::assertNotEmpty($data);
        self::assertStringStartsWith("\x89PNG", $data);
    }

    public function testToBase64ReturnsValidBase64(): void
    {
        $b64 = $this->loaded()->toPng()->toBase64();
        self::assertNotEmpty($b64);
        self::assertNotFalse(base64_decode($b64, true));
    }

    public function testToBlobReturnsImagickInstance(): void
    {
        $blob = $this->loaded()->toBlob();
        self::assertInstanceOf(Imagick::class, $blob);
    }

    public function testSaveWritesFileToDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imagick_save') . '.png';

        try {
            $this->loaded()->toPng()->save($path);
            self::assertFileExists($path);
            self::assertGreaterThan(0, filesize($path));
        } finally {
            @unlink($path);
        }
    }

    public function testToResourceWritesToStream(): void
    {
        $tmp = tmpfile();
        assert($tmp !== false);

        $this->loaded()->toPng()->toResource($tmp);
        self::assertGreaterThan(0, ftell($tmp));

        fclose($tmp);
    }

    public function testToStreamWritesToPsrStream(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::once())->method('write')->willReturn(0);

        $this->loaded()->toPng()->toStream($stream);
    }

    public function testToStringThrowsWhenNoImageLoaded(): void
    {
        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->toString();
    }

    // =========================================================================
    // MetadataInterface
    // =========================================================================

    public function testGetWidthAndHeightMatchCreationSize(): void
    {
        $driver = $this->loaded(80, 60);
        self::assertSame(80, $driver->getWidth());
        self::assertSame(60, $driver->getHeight());
    }

    public function testGetFormatReturnsPngForPngInput(): void
    {
        self::assertSame(Format::PNG, $this->loaded()->getFormat());
    }

    public function testGetExifReturnsArrayForJpegInput(): void
    {
        $driver = (new ImagickDriver())->fromString($this->makeJpeg());
        self::assertIsArray($driver->getExif());
    }

    public function testGetExifThrowsForNonJpeg(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->getExif(); // loaded as PNG
    }

    public function testSetExifAlwaysThrows(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->setExif(['Author' => 'Test']);
    }

    public function testGetIptcThrowsForNonJpeg(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->getIptc();
    }

    public function testSetIptcAlwaysThrows(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->setIptc([]);
    }

    public function testStripMetadataClearsExifArray(): void
    {
        $driver = (new ImagickDriver())->fromString($this->makeJpeg());
        $driver->stripMetadata();
        self::assertSame([], $driver->getExif());
    }

    // =========================================================================
    // ResizableInterface
    // =========================================================================

    public function testResizeFillProducesExactDimensions(): void
    {
        $driver = $this->loaded(100, 100);
        $driver->resize(50, 30, Fit::FILL);
        self::assertSame(50, $driver->getWidth());
        self::assertSame(30, $driver->getHeight());
    }

    public function testResizeContainProducesCorrectDimensions(): void
    {
        $driver = $this->loaded(100, 100);
        $driver->resize(50, 50, Fit::CONTAIN);
        self::assertSame(50, $driver->getWidth());
        self::assertSame(50, $driver->getHeight());
    }

    public function testResizeCoverProducesExactDimensions(): void
    {
        $driver = $this->loaded(100, 100);
        $driver->resize(40, 60, Fit::COVER);
        self::assertSame(40, $driver->getWidth());
        self::assertSame(60, $driver->getHeight());
    }

    public function testResizeWidthOnlyComputesMissingHeight(): void
    {
        $driver = $this->loaded(100, 50);
        $driver->resize(50, null, Fit::FILL);
        self::assertSame(50, $driver->getWidth());
        self::assertSame(25, $driver->getHeight());
    }

    public function testResizeHeightOnlyComputesMissingWidth(): void
    {
        $driver = $this->loaded(100, 50);
        $driver->resize(null, 25, Fit::FILL);
        self::assertSame(50, $driver->getWidth());
        self::assertSame(25, $driver->getHeight());
    }

    public function testResizeThrowsWhenBothDimensionsAreNull(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->resize(null, null);
    }

    public function testResizeThrowsOnNegativeWidth(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->resize(-10, 20);
    }

    public function testResizeThrowsOnZeroHeight(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->resize(20, 0);
    }

    public function testScaleHalvesDimensions(): void
    {
        $driver = $this->loaded(100, 80);
        $driver->scale(50);
        self::assertSame(50, $driver->getWidth());
        self::assertSame(40, $driver->getHeight());
    }

    public function testScaleThrowsOnZeroPercentage(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->scale(0);
    }

    public function testScaleThrowsOnNegativePercentage(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->scale(-10);
    }

    public function testResizeFillWithUpscaleFalseCapsDimensionsToSource(): void
    {
        $driver = $this->loaded(20, 20);
        $driver->resize(100, 100, Fit::FILL, upscale: false);
        self::assertSame(20, $driver->getWidth());
        self::assertSame(20, $driver->getHeight());
    }

    public function testResizeCoverWithUpscaleFalseDoesNotUpscale(): void
    {
        $driver = $this->loaded(20, 20);
        $driver->resize(100, 100, Fit::COVER, upscale: false);
        self::assertTrue($driver->isLoaded());
    }

    public function testResizeContainWithUpscaleFalseProducesTargetSizeCanvas(): void
    {
        $driver = $this->loaded(20, 20);
        $driver->resize(100, 100, Fit::CONTAIN, upscale: false);
        self::assertSame(100, $driver->getWidth());
        self::assertSame(100, $driver->getHeight());
    }

    // =========================================================================
    // CroppableInterface
    // =========================================================================

    public function testCropProducesCorrectDimensions(): void
    {
        $driver = $this->loaded(100, 100);
        $driver->crop(40, 30, 5, 5);
        self::assertSame(40, $driver->getWidth());
        self::assertSame(30, $driver->getHeight());
    }

    public function testCropThrowsOnNegativeDimension(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->crop(-1, 10);
    }

    public function testCropThrowsWhenRegionExceedsBounds(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        // 20×20 image, request 30 wide at x=0 → out of bounds
        $this->loaded(20, 20)->crop(30, 10);
    }

    public function testTrimOnSolidColorImageDoesNotThrow(): void
    {
        // Entirely uniform color → nothing meaningful to trim, image must remain loaded.
        $driver = $this->loaded(30, 30);
        $driver->trim(0);
        self::assertTrue($driver->isLoaded());
    }

    // =========================================================================
    // TransformableInterface
    // =========================================================================

    public function testRotate90DegreesSwapsDimensions(): void
    {
        $driver = $this->loaded(40, 20);
        $driver->rotate(90.0);
        // 40×20 rotated 90° clockwise → 20×40
        self::assertSame(20, $driver->getWidth());
        self::assertSame(40, $driver->getHeight());
    }

    public function testRotate0DegreesPreservesDimensions(): void
    {
        $driver = $this->loaded(30, 30);
        $driver->rotate(0.0);
        self::assertSame(30, $driver->getWidth());
        self::assertSame(30, $driver->getHeight());
    }

    public function testRotateThrowsOnNonFiniteAngle(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->rotate(INF);
    }

    public function testFlipHorizontalPreservesDimensions(): void
    {
        $driver = $this->loaded(30, 20);
        $driver->flip(Flip::HORIZONTAL);
        self::assertSame(30, $driver->getWidth());
        self::assertSame(20, $driver->getHeight());
    }

    public function testFlipVerticalPreservesDimensions(): void
    {
        $driver = $this->loaded(30, 20);
        $driver->flip(Flip::VERTICAL);
        self::assertSame(30, $driver->getWidth());
        self::assertSame(20, $driver->getHeight());
    }

    public function testFlipBothPreservesDimensions(): void
    {
        $driver = $this->loaded(30, 20);
        $driver->flip(Flip::BOTH);
        self::assertSame(30, $driver->getWidth());
        self::assertSame(20, $driver->getHeight());
    }

    // =========================================================================
    // FilterableInterface
    // =========================================================================

    public function testBlurExecutesWithoutException(): void
    {
        $this->loaded()->blur(2);
        self::assertTrue(true);
    }

    public function testBlurThrowsOnZeroAmount(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->blur(0);
    }

    public function testSharpenExecutesWithoutException(): void
    {
        $this->loaded()->sharpen(1);
        self::assertTrue(true);
    }

    public function testSharpenThrowsOnZeroAmount(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->sharpen(0);
    }

    public function testBrightnessExecutesWithoutException(): void
    {
        $this->loaded()->brightness(50);
        self::assertTrue(true);
    }

    public function testBrightnessThrowsWhenOutOfRange(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->brightness(101);
    }

    public function testContrastExecutesWithoutException(): void
    {
        $this->loaded()->contrast(-30);
        self::assertTrue(true);
    }

    public function testContrastThrowsWhenOutOfRange(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->contrast(-101);
    }

    public function testGrayscaleExecutesWithoutException(): void
    {
        $this->loaded()->grayscale();
        self::assertTrue(true);
    }

    public function testNegateExecutesWithoutException(): void
    {
        $this->loaded()->negate();
        self::assertTrue(true);
    }

    public function testSepiaExecutesWithoutException(): void
    {
        $this->loaded()->sepia();
        self::assertTrue(true);
    }

    public function testEmbossExecutesWithoutException(): void
    {
        $this->loaded()->emboss();
        self::assertTrue(true);
    }

    // =========================================================================
    // ConvertibleInterface
    // =========================================================================

    public function testToJpegChangesFormatAndOutputsMagicBytes(): void
    {
        $data = $this->loaded()->toJpeg(90)->toString();
        self::assertStringStartsWith("\xFF\xD8", $data);
        self::assertSame(Format::JPEG, $this->loaded()->toJpeg()->getFormat());
    }

    public function testToPngChangesFormatAndOutputsMagicBytes(): void
    {
        $data = $this->loaded()->toPng()->toString();
        self::assertStringStartsWith("\x89PNG", $data);
        self::assertSame(Format::PNG, $this->loaded()->toPng()->getFormat());
    }

    public function testToGifChangesFormatAndOutputsMagicBytes(): void
    {
        $data = $this->loaded()->toGif()->toString();
        self::assertStringStartsWith('GIF', $data);
        self::assertSame(Format::GIF, $this->loaded()->toGif()->getFormat());
    }

    public function testToWebpChangesFormatAndOutputsData(): void
    {
        $driver = $this->loaded()->toWebp();
        self::assertSame(Format::WEBP, $driver->getFormat());
        self::assertNotEmpty($driver->toString());
    }

    public function testToTiffChangesFormatIfSupported(): void
    {
        $driver = new ImagickDriver();
        if (!$driver->supportsFormat(Format::TIFF)) {
            $this->markTestSkipped('TIFF is not supported by this Imagick build.');
        }

        $result = $this->loaded()->toTiff();
        self::assertSame(Format::TIFF, $result->getFormat());
        self::assertNotEmpty($result->toString());
    }

    public function testToHeicChangesFormatIfSupported(): void
    {
        $driver = new ImagickDriver();
        if (!$driver->supportsFormat(Format::HEIC)) {
            $this->markTestSkipped('HEIC is not supported by this Imagick build.');
        }

        try {
            $result = $this->loaded()->toHeic();
            self::assertSame(Format::HEIC, $result->getFormat());
            self::assertNotEmpty($result->toString());
        } catch (ImageSaveException) {
            $this->markTestSkipped('HEIC encoding failed at runtime (codec not fully functional).');
        }
    }

    public function testToFormatDelegatesToCorrectConverter(): void
    {
        $driver = $this->loaded()->toFormat(Format::PNG);
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testToFormatThrowsForUnsupportedFormat(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        // ICO is not in the Imagick supported set for this driver
        $this->loaded()->toFormat(Format::ICO);
    }

    // =========================================================================
    // CompositionInterface
    // =========================================================================

    public function testRectDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->rect(10, 10, 30, 20, Color::fromRgba(255, 0, 0, 1.0));
        self::assertTrue(true);
    }

    public function testRectThrowsOnNonPositiveDimension(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->rect(0, 0, -5, 20, Color::fromRgba(0, 0, 0, 1.0));
    }

    public function testRectWithFillColorDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->rect(
            5,
            5,
            40,
            40,
            Color::fromRgba(255, 0, 0, 1.0),
            Color::fromRgba(0, 255, 0, 0.5),
        );
        self::assertTrue(true);
    }

    public function testEllipseDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->ellipse(50, 50, 40, 30, Color::fromRgba(0, 255, 0, 1.0));
        self::assertTrue(true);
    }

    public function testEllipseWithFillColorDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->ellipse(
            50,
            50,
            40,
            30,
            Color::fromRgba(0, 255, 0, 1.0),
            Color::fromRgb(255, 0, 128),
        );
        self::assertTrue(true);
    }

    public function testEllipseThrowsOnNonPositiveDimension(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->ellipse(10, 10, 0, 20, Color::fromRgba(0, 0, 0, 1.0));
    }

    public function testLineDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->line(0, 0, 99, 99, Color::fromRgba(0, 0, 255, 1.0));
        self::assertTrue(true);
    }

    public function testArcDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->arc(50, 50, 80, 80, 0, 180, Color::fromRgba(255, 128, 0, 1.0));
        self::assertTrue(true);
    }

    public function testArcWithFillColorDrawsWithoutException(): void
    {
        $this->loaded(100, 100)->arc(
            50,
            50,
            80,
            80,
            0,
            180,
            Color::fromRgba(255, 128, 0, 1.0),
            Color::fromRgb(128, 0, 255),
        );
        self::assertTrue(true);
    }

    public function testArcThrowsOnNonPositiveDimension(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->arc(10, 10, -1, 20, 0, 90, Color::fromRgba(0, 0, 0, 1.0));
    }

    public function testWatermarkOverlaysAnotherImagickDriverInstance(): void
    {
        $base      = $this->loaded(100, 100);
        $watermark = $this->loaded(20, 20);
        $base->watermark($watermark, Position::BOTTOM_RIGHT);
        self::assertTrue($base->isLoaded());
    }

    public function testWatermarkWithReducedOpacityAppliesAlphaBlend(): void
    {
        $base      = $this->loaded(100, 100);
        $watermark = $this->loaded(20, 20);
        $base->watermark($watermark, Position::TOP_LEFT, 50);
        self::assertTrue($base->isLoaded());
    }

    public function testWatermarkOpacityBoundaryThrows(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $base      = $this->loaded(100, 100);
        $watermark = $this->loaded(20, 20);
        $base->watermark($watermark, Position::BOTTOM_RIGHT, 200); // > 100
    }

    // =========================================================================
    // AnimatableInterface
    // =========================================================================

    public function testGetFrameCountReturns1WhenLoaded(): void
    {
        self::assertSame(1, $this->loaded()->getFrameCount());
    }

    public function testGetFrameCountReturns0WhenNotLoaded(): void
    {
        self::assertSame(0, (new ImagickDriver())->getFrameCount());
    }

    public function testGetFramesReturnsListWithSingleImagickInstance(): void
    {
        $frames = $this->loaded()->getFrames();
        self::assertCount(1, $frames);
        self::assertInstanceOf(Imagick::class, $frames[0]);
    }

    public function testGetFramesReturnsEmptyListWhenNotLoaded(): void
    {
        self::assertSame([], (new ImagickDriver())->getFrames());
    }

    public function testGetFrameReturnsImagickForIndex0(): void
    {
        $frame = $this->loaded()->getFrame(0);
        self::assertInstanceOf(Imagick::class, $frame);
    }

    public function testGetFrameThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->getFrame(1);
    }

    public function testGetFrameDelayReturns0ForSingleFramePng(): void
    {
        // Single-frame PNG has no animation delay → Imagick returns 0
        self::assertSame(0, $this->loaded()->getFrameDelay(0));
    }

    public function testGetFrameDelayThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->getFrameDelay(2);
    }

    public function testGetLoopCountReturns0ForAStillImage(): void
    {
        self::assertSame(0, $this->loaded()->getLoopCount());
    }

    // ─── Animation ────────────────────────────────────────────────────────────

    /**
     * Encoded animated GIF: one plain-colored frame per color, delays of 100 ms, 200 ms...
     *
     * @param list<string> $colors
     */
    private function makeAnimatedGif(array $colors = ['red', 'lime', 'blue'], int $width = 20, int $height = 10): string
    {
        $sequence = new Imagick();
        foreach ($colors as $i => $color) {
            $frame = new Imagick();
            $frame->newImage($width, $height, new \ImagickPixel($color), 'gif');
            $frame->setImageTicksPerSecond(100);
            $frame->setImageDelay(10 * ($i + 1));
            $sequence->addImage($frame);
        }

        $sequence->setIteratorIndex(0);
        $sequence->setImageIterations(2);
        $sequence->setFormat('gif');

        return $sequence->getImagesBlob();
    }

    private function animated(): ImagickDriver
    {
        return (new ImagickDriver())->fromString($this->makeAnimatedGif());
    }

    private function reread(string $data): Imagick
    {
        $imagick = new Imagick();
        $imagick->readImageBlob($data);

        return $imagick;
    }

    /**
     * Color of the center pixel of a frame, as "r,g,b".
     */
    private function centerColor(ImagickDriver $driver, int $index): string
    {
        /** @var Imagick $frame */
        $frame = $driver->getFrame($index);
        $rgb = $frame->getImagePixelColor(
            intdiv($frame->getImageWidth(), 2),
            intdiv($frame->getImageHeight(), 2),
        )->getColor();

        return $rgb['r'] . ',' . $rgb['g'] . ',' . $rgb['b'];
    }

    public function testAnimatedGifIsLoadedWithAllFramesAndTimings(): void
    {
        $driver = $this->animated();

        self::assertSame(Format::GIF, $driver->getFormat());
        self::assertSame(3, $driver->getFrameCount());
        self::assertCount(3, $driver->getFrames());
        self::assertSame(100, $driver->getFrameDelay(0));
        self::assertSame(300, $driver->getFrameDelay(2));
        self::assertSame(2, $driver->getLoopCount());
        self::assertSame('0,0,255', $this->centerColor($driver, 2));
    }

    public function testOperationsApplyToEveryFrame(): void
    {
        $driver = $this->animated()->resize(10, 4, Fit::FILL)->flip(Flip::HORIZONTAL)->negate();

        self::assertSame(3, $driver->getFrameCount());
        foreach ($driver->getFrames() as $frame) {
            self::assertSame(10, $frame->getImageWidth());
            self::assertSame(4, $frame->getImageHeight());
        }

        // Negated: red → cyan, lime → magenta, blue → yellow
        self::assertSame('0,255,255', $this->centerColor($driver, 0));
        self::assertSame('255,0,255', $this->centerColor($driver, 1));
        self::assertSame('255,255,0', $this->centerColor($driver, 2));
    }

    public function testReplacingCanvasKeepsTheFrameTimings(): void
    {
        $driver = $this->animated()->resize(30, 30, Fit::CONTAIN, background: Color::fromHex('#ffffff'));

        self::assertSame(3, $driver->getFrameCount());
        self::assertSame(30, $driver->getWidth());
        self::assertSame(200, $driver->getFrameDelay(1));
        self::assertSame(2, $driver->getLoopCount());
        self::assertSame('0,255,0', $this->centerColor($driver, 1));
    }

    public function testCropAppliesToEveryFrame(): void
    {
        $driver = $this->animated()->crop(5, 5, 2, 2);

        foreach ($driver->getFrames() as $frame) {
            self::assertSame([5, 5], [$frame->getImageWidth(), $frame->getImageHeight()]);
        }
    }

    public function testTrimKeepsTheBoxOfTheWidestFrame(): void
    {
        // White 40×40 frames: a black square at (5,5)-(14,14), then one at (20,25)-(29,34)
        $sequence = new Imagick();
        foreach ([[5, 5], [20, 25]] as [$x, $y]) {
            $frame = new Imagick();
            $frame->newImage(40, 40, new \ImagickPixel('white'), 'gif');
            $draw = new \ImagickDraw();
            $draw->setFillColor(new \ImagickPixel('black'));
            $draw->rectangle($x, $y, $x + 9, $y + 9);
            $frame->drawImage($draw);
            $sequence->addImage($frame);
        }
        $sequence->setFormat('gif');

        $driver = (new ImagickDriver())->fromString($sequence->getImagesBlob())->trim();

        self::assertSame(2, $driver->getFrameCount());
        foreach ($driver->getFrames() as $frame) {
            self::assertSame([25, 30], [$frame->getImageWidth(), $frame->getImageHeight()]);
        }
    }

    public function testWebpOutputKeepsTheAnimation(): void
    {
        if (Imagick::queryFormats('WEBP') === []) {
            self::markTestSkipped('No WebP support in this ImageMagick build.');
        }

        $output = $this->reread($this->animated()->toWebp()->toString());

        self::assertSame(3, $output->getNumberImages());
    }

    public function testGifOutputKeepsTheAnimation(): void
    {
        $output = $this->reread($this->animated()->resize(10, 5, Fit::FILL)->toGif()->toString());

        self::assertSame(3, $output->getNumberImages());
        $output->setIteratorIndex(1);
        self::assertSame(20, $output->getImageDelay());
        self::assertSame(10, $output->getImageWidth());
    }

    public function testStillFormatOutputKeepsTheFirstFrame(): void
    {
        $output = $this->reread($this->animated()->toPng()->toString());

        self::assertSame(1, $output->getNumberImages());
        $rgb = $output->getImagePixelColor(5, 5)->getColor();
        self::assertSame([255, 0, 0], [$rgb['r'], $rgb['g'], $rgb['b']]);
    }

    public function testCopyKeepsAllFrames(): void
    {
        $copy = $this->animated()->copy();

        self::assertSame(3, $copy->getFrameCount());
    }

    public function testAddFrameAppendsByDefault(): void
    {
        $frame = new Imagick();
        $frame->newImage(20, 10, new \ImagickPixel('white'), 'png');

        $driver = $this->animated()->addFrame($frame, 250);

        self::assertSame(4, $driver->getFrameCount());
        self::assertSame(250, $driver->getFrameDelay(3));
        self::assertSame('255,255,255', $this->centerColor($driver, 3));
        self::assertSame(2, $driver->getLoopCount());
    }

    public function testAddFrameInsertsAtIndex(): void
    {
        $frame = new Imagick();
        $frame->newImage(20, 10, new \ImagickPixel('white'), 'png');

        $driver = $this->animated()->addFrame($frame, 50, 0);

        self::assertSame(4, $driver->getFrameCount());
        self::assertSame('255,255,255', $this->centerColor($driver, 0));
        self::assertSame(50, $driver->getFrameDelay(0));
        self::assertSame('255,0,0', $this->centerColor($driver, 1));
    }

    public function testAddFrameTurnsAStillImageIntoAnAnimation(): void
    {
        $frame = new Imagick();
        $frame->newImage(20, 20, new \ImagickPixel('white'), 'png');

        $output = $this->reread($this->loaded()->addFrame($frame, 100)->toGif()->toString());

        self::assertSame(2, $output->getNumberImages());
    }

    public function testAddFrameRejectsADifferentSize(): void
    {
        $frame = new Imagick();
        $frame->newImage(5, 5, new \ImagickPixel('white'), 'png');

        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->addFrame($frame, 100);
    }

    public function testAddFrameRejectsANonImagickFrame(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->addFrame('not a frame', 100);
    }

    public function testAddFrameRejectsANegativeDelay(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->addFrame(new Imagick(), -1);
    }

    public function testAddFrameRejectsAnOutOfBoundsIndex(): void
    {
        $frame = new Imagick();
        $frame->newImage(20, 10, new \ImagickPixel('white'), 'png');

        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->addFrame($frame, 100, 4);
    }

    public function testAddFrameCountsTheNewFrameInThePixelLimit(): void
    {
        $frame = new Imagick();
        $frame->newImage(20, 10, new \ImagickPixel('white'), 'png');

        $driver = (new ImagickDriver())->limitPixels(20 * 10 * 3)->fromString($this->makeAnimatedGif());

        $this->expectException(ImageLimitExceededException::class);
        $driver->addFrame($frame, 100);
    }

    public function testRemoveFrame(): void
    {
        $driver = $this->animated()->removeFrame(0);

        self::assertSame(2, $driver->getFrameCount());
        self::assertSame('0,255,0', $this->centerColor($driver, 0));
        self::assertSame(2, $driver->getLoopCount());
    }

    public function testRemoveFrameRefusesTheOnlyFrame(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->removeFrame(0);
    }

    public function testRemoveFrameThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->removeFrame(3);
    }

    public function testSetFrameDelayRoundsToHundredthsOfASecond(): void
    {
        $driver = $this->animated()->setFrameDelay(1, 500)->setFrameDelay(2, 25);

        self::assertSame(500, $driver->getFrameDelay(1));
        self::assertSame(30, $driver->getFrameDelay(2));
        self::assertSame(100, $driver->getFrameDelay(0));
    }

    public function testSetFrameDelayRejectsANegativeDelay(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->setFrameDelay(0, -10);
    }

    public function testSetFrameDelayThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->setFrameDelay(5, 100);
    }

    public function testSetLoopCountIsWrittenToTheOutput(): void
    {
        $driver = $this->animated()->setLoopCount(5);
        self::assertSame(5, $driver->getLoopCount());

        $output = $this->reread($driver->toString());
        self::assertSame(5, $output->getImageIterations());
    }

    public function testSetLoopCountRejectsANegativeCount(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->animated()->setLoopCount(-1);
    }

    public function testPixelLimitCountsAllFrames(): void
    {
        $this->expectException(ImageLimitExceededException::class);
        (new ImagickDriver())->limitPixels(20 * 10 * 2)->fromString($this->makeAnimatedGif());
    }

    public function testAnimationOperationsAreSupported(): void
    {
        $driver = new ImagickDriver();

        foreach (['addFrame', 'removeFrame', 'setFrameDelay', 'setLoopCount'] as $operation) {
            self::assertTrue($driver->supportsOperation($operation), $operation);
        }
    }

    // =========================================================================
    // CapabilityInterface
    // =========================================================================

    public function testSupportsJpegFormat(): void
    {
        self::assertTrue((new ImagickDriver())->supportsFormat(Format::JPEG));
    }

    public function testSupportsPngFormat(): void
    {
        self::assertTrue((new ImagickDriver())->supportsFormat(Format::PNG));
    }

    public function testSupportsGifFormat(): void
    {
        self::assertTrue((new ImagickDriver())->supportsFormat(Format::GIF));
    }

    public function testSupportsTiffFormat(): void
    {
        // Imagick natively supports TIFF (unlike GDDriver)
        self::assertTrue((new ImagickDriver())->supportsFormat(Format::TIFF));
    }

    public function testSupportsWebpFormat(): void
    {
        self::assertTrue((new ImagickDriver())->supportsFormat(Format::WEBP));
    }

    public function testSupportsResizeOperation(): void
    {
        self::assertTrue((new ImagickDriver())->supportsOperation('resize'));
    }

    public function testSupportsRotateOperation(): void
    {
        self::assertTrue((new ImagickDriver())->supportsOperation('rotate'));
    }

    public function testSupportsToTiffOperation(): void
    {
        // ImagickDriver explicitly supports toTiff (unlike GDDriver)
        self::assertTrue((new ImagickDriver())->supportsOperation('toTiff'));
    }

    public function testDoesNotSupportUnknownOperation(): void
    {
        self::assertFalse((new ImagickDriver())->supportsOperation('unknownOperation'));
    }

    public function testGetSupportedFormatsAlwaysContainsBaseTriple(): void
    {
        $formats = (new ImagickDriver())->getSupportedFormats();
        self::assertContains(Format::JPEG, $formats);
        self::assertContains(Format::PNG, $formats);
        self::assertContains(Format::GIF, $formats);
    }

    public function testGetSupportedFormatsContainsTiff(): void
    {
        // Imagick builds typically include TIFF support
        $formats = (new ImagickDriver())->getSupportedFormats();
        self::assertContains(Format::TIFF, $formats);
    }

    // =========================================================================
    // ImageStateInterface
    // =========================================================================

    public function testIsLoadedReturnsFalseBeforeAnyInput(): void
    {
        self::assertFalse((new ImagickDriver())->isLoaded());
    }

    public function testIsLoadedReturnsTrueAfterFromString(): void
    {
        self::assertTrue($this->loaded()->isLoaded());
    }

    public function testCopyCreatesIndependentInstance(): void
    {
        $original = $this->loaded(50, 50);
        $copy     = $original->copy();

        self::assertTrue($copy->isLoaded());
        self::assertSame($original->getWidth(), $copy->getWidth());
        self::assertSame($original->getHeight(), $copy->getHeight());
        self::assertSame($original->getFormat(), $copy->getFormat());

        // Resize original — copy must be unaffected.
        $original->resize(10, 10, Fit::FILL);
        self::assertSame(50, $copy->getWidth());
        self::assertSame(50, $copy->getHeight());
    }

    public function testDestroyUnsetsLoadedState(): void
    {
        $driver = $this->loaded();
        self::assertTrue($driver->isLoaded());
        $driver->destroy();
        self::assertFalse($driver->isLoaded());
    }

    // =========================================================================
    // fromUrl
    // =========================================================================

    public function testFromUrlThrowsOnUnreachableUrl(): void
    {
        $nonExistent = 'file://' . sys_get_temp_dir() . '/imagick_no_such_image_test_xyz.png';

        $this->expectException(ImageLoadException::class);
        (new ImagickDriver())->fromUrl($nonExistent);
    }

    // =========================================================================
    // toAvif / toBmp / toHeic — conditional format setters
    // =========================================================================

    public function testToAvifThrowsWhenUnsupportedOrSetsFormatWhenSupported(): void
    {
        $driver = $this->loaded();

        if (empty(Imagick::queryFormats('AVIF'))) {
            $this->expectException(UnsupportedFormatException::class);
            $driver->toAvif();
        } else {
            $driver->toAvif(70);
            self::assertSame(Format::AVIF, $driver->getFormat());
        }
    }

    public function testToBmpThrowsWhenUnsupportedOrSetsFormatWhenSupported(): void
    {
        $driver = $this->loaded();

        if (empty(Imagick::queryFormats('BMP'))) {
            $this->expectException(UnsupportedFormatException::class);
            $driver->toBmp();
        } else {
            $driver->toBmp();
            self::assertSame(Format::BMP, $driver->getFormat());
        }
    }

    public function testToHeicThrowsWhenUnsupportedOrSetsFormatWhenSupported(): void
    {
        $driver = $this->loaded();

        if (empty(Imagick::queryFormats('HEIC'))) {
            $this->expectException(UnsupportedFormatException::class);
            $driver->toHeic();
        } else {
            $driver->toHeic(80);
            self::assertSame(Format::HEIC, $driver->getFormat());
        }
    }

    // =========================================================================
    // text — validation
    // =========================================================================

    public function testTextThrowsOnInvalidFontSize(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->text('hello', 10, 10, '/some/font.ttf', 0, Color::fromRgb(0, 0, 0));
    }

    public function testTextThrowsOnMissingFontFile(): void
    {
        $this->expectException(ImageLoadException::class);
        $this->loaded()->text('hello', 10, 10, '/nonexistent/font_xyz.ttf', 12, Color::fromRgb(0, 0, 0));
    }

    // =========================================================================
    // watermark — OutputInterface path and incompatible type
    // =========================================================================

    public function testWatermarkViaOutputInterfaceAppliesOverlay(): void
    {
        // ImagickDriver is final, so createStub() is not available.
        // Build a lightweight anonymous class that satisfies both ImageInterface
        // (required by the method signature) and OutputInterface (the branch under
        // test). Since it is not an ImagickDriver, the self-path is skipped.
        $png = $this->makePng(10, 10);

        $outputWm = new class ($png) implements ImageInterface, OutputInterface {
            public function __construct(private readonly string $data) {}

            public function save(string $path): static
            {
                file_put_contents($path, $this->data);
                return $this;
            }

            public function toString(): string
            {
                return $this->data;
            }

            public function toBase64(): string
            {
                return base64_encode($this->data);
            }

            public function toBlob(): mixed
            {
                return $this->data;
            }

            public function toResource(mixed $resource): static
            {
                fwrite($resource, $this->data);
                return $this;
            }

            public function toStream(\Psr\Http\Message\StreamInterface $stream): static
            {
                $stream->write($this->data);
                return $this;
            }
        };

        $base = $this->loaded(100, 100);
        $base->watermark($outputWm, Position::TOP_LEFT);

        self::assertTrue($base->isLoaded());
    }

    public function testWatermarkThrowsForIncompatibleType(): void
    {
        $this->expectException(UnsupportedOperationException::class);

        $incompatible = new class implements ImageInterface {};
        $this->loaded(100, 100)->watermark($incompatible, Position::TOP_LEFT);
    }

    // =========================================================================
    // DriverAvailabilityInterface
    // =========================================================================

    public function testIsAvailableReturnsTrueWhenImagickIsLoaded(): void
    {
        self::assertTrue(ImagickDriver::isAvailable());
    }

    // =========================================================================
    // fromBlank
    // =========================================================================

    public function testFromBlankCreatesLoadedPngCanvas(): void
    {
        $driver = (new ImagickDriver())->fromBlank(40, 30);

        self::assertTrue($driver->isLoaded());
        self::assertSame(40, $driver->getWidth());
        self::assertSame(30, $driver->getHeight());
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testFromBlankWithNullBackgroundIsFullyTransparent(): void
    {
        $driver  = (new ImagickDriver())->fromBlank(10, 10);
        $imagick = $driver->toBlob();
        self::assertInstanceOf(Imagick::class, $imagick);

        $pixel = $imagick->getImagePixelColor(5, 5);
        self::assertSame(0.0, $pixel->getColorValue(Imagick::COLOR_ALPHA));
    }

    public function testFromBlankWithBackgroundFillsCanvas(): void
    {
        $driver  = (new ImagickDriver())->fromBlank(10, 10, Color::fromRgb(255, 0, 0));
        $imagick = $driver->toBlob();
        self::assertInstanceOf(Imagick::class, $imagick);

        $pixel = $imagick->getImagePixelColor(5, 5);
        self::assertSame(255, $pixel->getColor()['r']);
    }

    public function testFromBlankThrowsOnZeroWidth(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        (new ImagickDriver())->fromBlank(0, 10);
    }

    public function testFromBlankThrowsOnNegativeHeight(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        (new ImagickDriver())->fromBlank(10, -5);
    }

    public function testFromBlankAllowsDrawingOperations(): void
    {
        $driver = (new ImagickDriver())->fromBlank(50, 50);
        $driver->rect(5, 5, 20, 20, Color::fromRgb(0, 0, 0), Color::fromRgb(0, 255, 0));
        self::assertTrue($driver->isLoaded());
    }

    // =========================================================================
    // text — nullable font (unsupported for Imagick) and anchoring
    // =========================================================================

    public function testDoesNotSupportBuiltinFontOperation(): void
    {
        self::assertFalse((new ImagickDriver())->supportsOperation('text.builtinFont'));
    }

    public function testTextWithNullFontPathThrowsUnsupportedOperationException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded(100, 50)->text('Hello', 10, 10, null, 12, Color::fromRgb(0, 0, 0));
    }

    public function testTextWithAnchorMiddleCenterRendersWithoutException(): void
    {
        $fontPath = $this->findSystemTtfFont();
        if ($fontPath === null) {
            $this->markTestSkipped('No TrueType font file found on this system.');
        }

        $driver = $this->loaded(200, 100);
        $driver->text(
            'Hi',
            100,
            50,
            $fontPath,
            16,
            Color::fromRgb(0, 0, 0),
            anchor: Position::MIDDLE_CENTER,
        );
        self::assertTrue($driver->isLoaded());
    }

    public function testTextDefaultAnchorIsTopLeft(): void
    {
        $fontPath = $this->findSystemTtfFont();
        if ($fontPath === null) {
            $this->markTestSkipped('No TrueType font file found on this system.');
        }

        $driver = $this->loaded(200, 100);
        $driver->text('Hi', 2, 2, $fontPath, 16, Color::fromRgb(0, 0, 0));
        self::assertTrue($driver->isLoaded());
    }

    /**
     * Locate a TrueType font file available on the current system, if any.
     */
    private function findSystemTtfFont(): ?string
    {
        $candidates = [
            'C:\\Windows\\Fonts\\arial.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/freefont/FreeSans.ttf',
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    // =========================================================================
    // PixelLimitInterface
    // =========================================================================

    public function testLimitPixelsIsInactiveByDefault(): void
    {
        $driver = (new ImagickDriver())->fromString($this->makePng(500, 500));
        self::assertTrue($driver->isLoaded());
    }

    public function testLimitPixelsThrowsOnInvalidValue(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        (new ImagickDriver())->limitPixels(0);
    }

    public function testLimitPixelsAllowsNullToDisable(): void
    {
        $driver = (new ImagickDriver())->limitPixels(100)->limitPixels(null);
        $driver->fromString($this->makePng(50, 50)); // 2500 px, would exceed 100
        self::assertTrue($driver->isLoaded());
    }

    public function testLimitPixelsRejectsFromStringBasedOnHeaderWithoutDecoding(): void
    {
        // 50000×50000 header, but no real pixel data behind it: pingImageBlob()
        // only reads the header, so this must fail cleanly with our exception
        // rather than attempting (and failing) a full decode.
        $data = $this->buildTruncatedPngWithHeaderOnly(50000, 50000);

        $this->expectException(ImageLimitExceededException::class);
        (new ImagickDriver())->limitPixels(1_000_000)->fromString($data);
    }

    public function testLimitPixelsRejectsFromFileBasedOnHeaderWithoutDecoding(): void
    {
        $data = $this->buildTruncatedPngWithHeaderOnly(50000, 50000);
        $path = tempnam(sys_get_temp_dir(), 'imagick_limit_') . '.png';
        file_put_contents($path, $data);

        try {
            $this->expectException(ImageLimitExceededException::class);
            (new ImagickDriver())->limitPixels(1_000_000)->fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testLimitPixelsAllowsImageUnderTheLimit(): void
    {
        $driver = (new ImagickDriver())->limitPixels(1_000_000)->fromString($this->makePng(100, 100));
        self::assertTrue($driver->isLoaded());
    }

    public function testLimitPixelsRejectsAlreadyDecodedImagickViaFromBlob(): void
    {
        // The image is already fully decoded when handed to fromBlob(): the
        // guard cannot prevent the earlier memory allocation, but it must
        // still refuse to proceed with an object that violates the configured limit.
        $imagick = new Imagick();
        $imagick->readImageBlob($this->makePng(200, 200));

        $this->expectException(ImageLimitExceededException::class);
        (new ImagickDriver())->limitPixels(1_000)->fromBlob($imagick);
    }

    public function testLimitPixelsRejectsFromBlankExceedingTheLimit(): void
    {
        // No header to inspect here — the guard checks width × height directly.
        $this->expectException(ImageLimitExceededException::class);
        (new ImagickDriver())->limitPixels(1_000)->fromBlank(50, 50); // 2500 px
    }

    /**
     * Build a well-formed PNG signature + IHDR chunk announcing $width×$height,
     * with a corrupt/truncated IDAT — parseable by pingImageBlob() (header only,
     * which never reads past IHDR), but guaranteed to fail a full readImageBlob() decode.
     */
    private function buildTruncatedPngWithHeaderOnly(int $width, int $height): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data))
            . $type . $data . pack('N', crc32($type . $data));

        $signature = "\x89PNG\r\n\x1a\n";
        $ihdrChunk = $chunk('IHDR', pack('NN', $width, $height) . pack('C5', 8, 6, 0, 0, 0));
        $idatChunk = $chunk('IDAT', "\x78\x9c\x00\x00"); // deliberately incomplete zlib stream
        $iendChunk = $chunk('IEND', '');

        return $signature . $ihdrChunk . $idatChunk . $iendChunk;
    }

    // =========================================================================
    // Native warning capture — enriched exception messages
    // =========================================================================

    public function testSaveThrowsWithEnrichedMessageWhenDirectoryDoesNotExist(): void
    {
        $this->expectException(ImageSaveException::class);
        $this->loaded()->toPng()->save('/nonexistent/directory/xyz/output.png');
    }
    // =========================================================================
    // Review fixes
    // =========================================================================

    public function testRefusesDataThatIsNotARasterImage(): void
    {
        // An SVG would reach ImageMagick's MSVG/MVG coders (local file reads).
        $svg = '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';

        $this->expectException(ImageLoadException::class);
        $this->expectExceptionMessage('not a supported raster image');

        (new ImagickDriver())->fromString($svg);
    }

    public function testFromFileAppliesTheSameSignatureCheck(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'imagick_svg_');
        self::assertIsString($path);
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');

        try {
            $this->expectException(ImageLoadException::class);
            (new ImagickDriver())->fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testFromUrlRefusesAnythingButHttp(): void
    {
        $this->expectException(ImageLoadException::class);

        (new ImagickDriver())->fromUrl('file:///etc/passwd');
    }

    public function testQualityOutsideTheDocumentedRangeIsRefused(): void
    {
        $this->expectException(InvalidImageArgumentException::class);

        $this->loaded()->toPng(85);
    }

    public function testResizeIsBoundByThePixelLimit(): void
    {
        $driver = (new ImagickDriver())->limitPixels(10_000)->fromString($this->makePng(20, 20));

        $this->expectException(ImageLimitExceededException::class);

        $driver->resize(1000, 1000, Fit::FILL);
    }

    public function testCoverWithoutUpscaleKeepsTheTargetCanvas(): void
    {
        $driver = $this->loaded(20, 20)->resize(100, 100, Fit::COVER, upscale: false);

        self::assertSame(100, $driver->getWidth());
        self::assertSame(100, $driver->getHeight());
    }

    public function testPngOutputIsCompressedByDefault(): void
    {
        // 200x200 RGBA uncompressed is ~160 kB; a uniform image compresses to almost nothing.
        $png = (new ImagickDriver())->fromBlank(200, 200, Color::fromRgba(10, 20, 30, 1.0))->toPng()->toString();

        self::assertLessThan(10_000, strlen($png));
    }
}
