<?php

declare(strict_types=1);

namespace G\Image\Tests\Driver;

use G\Image\Color\Color;
use G\Image\Driver\GDDriver;
use G\Image\Enum\Fit;
use G\Image\Enum\Flip;
use G\Image\Enum\Format;
use G\Image\Enum\Position;
use G\Image\Exception\ImageLoadException;
use G\Image\Exception\InvalidImageArgumentException;
use G\Image\Exception\UnsupportedFormatException;
use G\Image\Exception\UnsupportedOperationException;
use GdImage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

#[CoversClass(GDDriver::class)]
#[RequiresPhpExtension('gd')]
final class GDDriverTest extends TestCase
{
    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a minimal in-memory PNG binary string using GD directly.
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
     * Build a minimal in-memory JPEG binary string using GD directly.
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
     * Return a loaded GDDriver instance backed by a 20×20 PNG.
     */
    private function loaded(int $width = 20, int $height = 20): GDDriver
    {
        return (new GDDriver())->fromString($this->makePng($width, $height));
    }

    // =========================================================================
    // Constructor
    // =========================================================================

    public function testInstantiationSucceedsWhenGdIsLoaded(): void
    {
        $driver = new GDDriver();
        self::assertFalse($driver->isLoaded());
    }

    // =========================================================================
    // InputInterface
    // =========================================================================

    public function testFromStringLoadsPng(): void
    {
        $driver = (new GDDriver())->fromString($this->makePng());
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testFromStringLoadsJpeg(): void
    {
        $driver = (new GDDriver())->fromString($this->makeJpeg());
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::JPEG, $driver->getFormat());
    }

    public function testFromStringThrowsOnInvalidData(): void
    {
        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromString('not image data at all');
    }

    public function testFromBase64LoadsImage(): void
    {
        $driver = (new GDDriver())->fromBase64(base64_encode($this->makePng()));
        self::assertTrue($driver->isLoaded());
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testFromBase64ThrowsOnInvalidPayload(): void
    {
        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromBase64('!!!invalid base64!!!');
    }

    public function testFromBlobAcceptsGdImage(): void
    {
        $gd = imagecreatefromstring($this->makePng());
        assert($gd instanceof GdImage);

        $driver = (new GDDriver())->fromBlob($gd);
        self::assertTrue($driver->isLoaded());
    }

    public function testFromBlobAcceptsBinaryString(): void
    {
        $driver = (new GDDriver())->fromBlob($this->makePng());
        self::assertTrue($driver->isLoaded());
    }

    public function testFromBlobThrowsOnUnsupportedType(): void
    {
        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromBlob(42);
    }

    public function testFromResourceLoadsImage(): void
    {
        $tmp = tmpfile();
        assert($tmp !== false);

        fwrite($tmp, $this->makePng());
        rewind($tmp);

        $driver = (new GDDriver())->fromResource($tmp);
        self::assertTrue($driver->isLoaded());

        fclose($tmp);
    }

    public function testFromResourceThrowsOnNonResource(): void
    {
        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromResource('not a resource');
    }

    public function testFromStreamLoadsImage(): void
    {
        $png    = $this->makePng();
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn($png);

        $driver = (new GDDriver())->fromStream($stream);
        self::assertTrue($driver->isLoaded());
    }

    public function testFromStreamThrowsOnEmptyStream(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willReturn('');

        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromStream($stream);
    }

    public function testFromFileLoadsImageFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gd_test') . '.png';
        file_put_contents($path, $this->makePng());

        try {
            $driver = (new GDDriver())->fromFile($path);
            self::assertTrue($driver->isLoaded());
            self::assertSame(Format::PNG, $driver->getFormat());
        } finally {
            @unlink($path);
        }
    }

    public function testFromFileThrowsOnMissingFile(): void
    {
        $this->expectException(ImageLoadException::class);
        (new GDDriver())->fromFile('/nonexistent/path/image.png');
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

    public function testToBlobReturnsGdImage(): void
    {
        $blob = $this->loaded()->toBlob();
        self::assertInstanceOf(GdImage::class, $blob);
    }

    public function testSaveWritesFileToDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gd_save') . '.png';

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
        (new GDDriver())->toString();
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
        $driver = (new GDDriver())->fromString($this->makeJpeg());
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
        $driver = (new GDDriver())->fromString($this->makeJpeg());
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
        // Entirely uniform color → nothing to trim, image must remain loaded.
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

    public function testToTiffAlwaysThrowsUnsupportedFormatException(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->loaded()->toTiff();
    }

    public function testToHeicAlwaysThrowsUnsupportedFormatException(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->loaded()->toHeic();
    }

    public function testToFormatDelegatesToCorrectConverter(): void
    {
        $driver = $this->loaded()->toFormat(Format::PNG);
        self::assertSame(Format::PNG, $driver->getFormat());
    }

    public function testToFormatThrowsForTiff(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        $this->loaded()->toFormat(Format::TIFF);
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

    public function testArcThrowsOnNonPositiveDimension(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->arc(10, 10, -1, 20, 0, 90, Color::fromRgba(0, 0, 0, 1.0));
    }

    public function testWatermarkOverlaysAnotherGdDriverInstance(): void
    {
        $base      = $this->loaded(100, 100);
        $watermark = $this->loaded(20, 20);
        $base->watermark($watermark, Position::BOTTOM_RIGHT);
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
        self::assertSame(0, (new GDDriver())->getFrameCount());
    }

    public function testGetFramesReturnsListWithSingleGdImage(): void
    {
        $frames = $this->loaded()->getFrames();
        self::assertCount(1, $frames);
        self::assertInstanceOf(GdImage::class, $frames[0]);
    }

    public function testGetFramesReturnsEmptyListWhenNotLoaded(): void
    {
        self::assertSame([], (new GDDriver())->getFrames());
    }

    public function testGetFrameReturnsGdImageForIndex0(): void
    {
        $frame = $this->loaded()->getFrame(0);
        self::assertInstanceOf(GdImage::class, $frame);
    }

    public function testGetFrameThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->getFrame(1);
    }

    public function testAddFrameThrowsUnsupportedOperationException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->addFrame(null, 100);
    }

    public function testRemoveFrameThrowsUnsupportedOperationException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->removeFrame(0);
    }

    public function testGetFrameDelayReturns0ForIndex0(): void
    {
        self::assertSame(0, $this->loaded()->getFrameDelay(0));
    }

    public function testGetFrameDelayThrowsForOutOfBoundsIndex(): void
    {
        $this->expectException(InvalidImageArgumentException::class);
        $this->loaded()->getFrameDelay(2);
    }

    public function testSetFrameDelayThrowsUnsupportedOperationException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->setFrameDelay(0, 100);
    }

    public function testGetLoopCountAlwaysReturns0(): void
    {
        self::assertSame(0, $this->loaded()->getLoopCount());
    }

    public function testSetLoopCountThrowsUnsupportedOperationException(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->loaded()->setLoopCount(3);
    }

    // =========================================================================
    // CapabilityInterface
    // =========================================================================

    public function testSupportsJpegFormat(): void
    {
        self::assertTrue((new GDDriver())->supportsFormat(Format::JPEG));
    }

    public function testSupportsPngFormat(): void
    {
        self::assertTrue((new GDDriver())->supportsFormat(Format::PNG));
    }

    public function testSupportsGifFormat(): void
    {
        self::assertTrue((new GDDriver())->supportsFormat(Format::GIF));
    }

    public function testDoesNotSupportTiffFormat(): void
    {
        self::assertFalse((new GDDriver())->supportsFormat(Format::TIFF));
    }

    public function testDoesNotSupportHeicFormat(): void
    {
        self::assertFalse((new GDDriver())->supportsFormat(Format::HEIC));
    }

    public function testSupportsResizeOperation(): void
    {
        self::assertTrue((new GDDriver())->supportsOperation('resize'));
    }

    public function testSupportsRotateOperation(): void
    {
        self::assertTrue((new GDDriver())->supportsOperation('rotate'));
    }

    public function testDoesNotSupportUnknownOperation(): void
    {
        self::assertFalse((new GDDriver())->supportsOperation('unknownOperation'));
    }

    public function testGetSupportedFormatsAlwaysContainsBaseTriple(): void
    {
        $formats = (new GDDriver())->getSupportedFormats();
        self::assertContains(Format::JPEG, $formats);
        self::assertContains(Format::PNG, $formats);
        self::assertContains(Format::GIF, $formats);
    }

    // =========================================================================
    // ImageStateInterface
    // =========================================================================

    public function testIsLoadedReturnsFalseBeforeAnyInput(): void
    {
        self::assertFalse((new GDDriver())->isLoaded());
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

    public function testOperationThrowsAfterDestroy(): void
    {
        $driver = $this->loaded();
        $driver->destroy();

        $this->expectException(ImageLoadException::class);
        $driver->toString();
    }

    // =========================================================================
    // Fluent chaining
    // =========================================================================

    public function testMethodChainingReturnsSameInstance(): void
    {
        $driver = $this->loaded(60, 60);
        $result = $driver
            ->resize(40, 40, Fit::FILL)
            ->grayscale()
            ->toPng();

        self::assertSame($driver, $result);
    }

    public function testCompleteRoundTrip(): void
    {
        // Load → transform → save → reload → verify dimensions
        $tmpPath = tempnam(sys_get_temp_dir(), 'gd_rt') . '.png';

        try {
            $this->loaded(100, 100)
                ->resize(60, 40, Fit::FILL)
                ->toPng()
                ->save($tmpPath);

            $reloaded = (new GDDriver())->fromFile($tmpPath);
            self::assertSame(60, $reloaded->getWidth());
            self::assertSame(40, $reloaded->getHeight());
            self::assertSame(Format::PNG, $reloaded->getFormat());
        } finally {
            @unlink($tmpPath);
        }
    }
}
