<?php

declare(strict_types=1);

namespace G\Image\Tests\Enum;

use G\Image\Enum\Format;
use G\Image\Exception\UnsupportedFormatException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Format::class)]
final class FormatTest extends TestCase
{
    // =========================================================================
    // fromMimeType
    // =========================================================================

    public function testFromMimeTypeReturnsCorrectFormat(): void
    {
        self::assertSame(Format::JPEG, Format::fromMimeType('image/jpeg'));
        self::assertSame(Format::PNG,  Format::fromMimeType('image/png'));
        self::assertSame(Format::WEBP, Format::fromMimeType('image/webp'));
        self::assertSame(Format::GIF,  Format::fromMimeType('image/gif'));
        self::assertSame(Format::TIFF, Format::fromMimeType('image/tiff'));
        self::assertSame(Format::HEIC, Format::fromMimeType('image/heic'));
        self::assertSame(Format::BMP,  Format::fromMimeType('image/bmp'));
        self::assertSame(Format::AVIF, Format::fromMimeType('image/avif'));
    }

    public function testFromMimeTypeIsCaseInsensitive(): void
    {
        self::assertSame(Format::JPEG, Format::fromMimeType('IMAGE/JPEG'));
    }

    public function testFromMimeTypeThrowsOnUnknownMime(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        Format::fromMimeType('application/pdf');
    }

    // =========================================================================
    // fromExtension
    // =========================================================================

    public function testFromExtensionJpgReturnsJpeg(): void
    {
        self::assertSame(Format::JPEG, Format::fromExtension('jpg'));
        self::assertSame(Format::JPEG, Format::fromExtension('jpeg'));
    }

    public function testFromExtensionWithLeadingDot(): void
    {
        self::assertSame(Format::PNG, Format::fromExtension('.png'));
    }

    public function testFromExtensionIsCaseInsensitive(): void
    {
        self::assertSame(Format::WEBP, Format::fromExtension('WEBP'));
    }

    public function testFromExtensionTifAlias(): void
    {
        self::assertSame(Format::TIFF, Format::fromExtension('tif'));
        self::assertSame(Format::TIFF, Format::fromExtension('tiff'));
    }

    public function testFromExtensionHeifAlias(): void
    {
        self::assertSame(Format::HEIC, Format::fromExtension('heif'));
    }

    public function testFromExtensionJp2Aliases(): void
    {
        self::assertSame(Format::JP2, Format::fromExtension('jp2'));
        self::assertSame(Format::JP2, Format::fromExtension('jpx'));
        self::assertSame(Format::JP2, Format::fromExtension('j2k'));
    }

    public function testFromExtensionThrowsOnUnknownExtension(): void
    {
        $this->expectException(UnsupportedFormatException::class);
        Format::fromExtension('xyz');
    }

    // =========================================================================
    // tryFromExtension
    // =========================================================================

    public function testTryFromExtensionReturnsNullOnUnknown(): void
    {
        self::assertNull(Format::tryFromExtension('unknown'));
    }

    public function testTryFromExtensionReturnsFormatOnKnown(): void
    {
        self::assertSame(Format::PNG, Format::tryFromExtension('png'));
    }

    // =========================================================================
    // toExtension
    // =========================================================================

    public function testToExtensionReturnsExpectedStrings(): void
    {
        self::assertSame('jpg',  Format::JPEG->toExtension());
        self::assertSame('png',  Format::PNG->toExtension());
        self::assertSame('webp', Format::WEBP->toExtension());
        self::assertSame('avif', Format::AVIF->toExtension());
        self::assertSame('gif',  Format::GIF->toExtension());
        self::assertSame('bmp',  Format::BMP->toExtension());
        self::assertSame('tiff', Format::TIFF->toExtension());
        self::assertSame('heic', Format::HEIC->toExtension());
        self::assertSame('ico',  Format::ICO->toExtension());
        self::assertSame('wbmp', Format::WBMP->toExtension());
        self::assertSame('xbm',  Format::XBM->toExtension());
        self::assertSame('jp2',  Format::JP2->toExtension());
    }

    // =========================================================================
    // label
    // =========================================================================

    public function testLabelReturnsHumanReadableString(): void
    {
        self::assertSame('JPEG',       Format::JPEG->label());
        self::assertSame('PNG',        Format::PNG->label());
        self::assertSame('WebP',       Format::WEBP->label());
        self::assertSame('AVIF',       Format::AVIF->label());
        self::assertSame('GIF',        Format::GIF->label());
        self::assertSame('Bitmap',     Format::BMP->label());
        self::assertSame('TIFF',       Format::TIFF->label());
        self::assertSame('HEIC',       Format::HEIC->label());
        self::assertSame('ICO',        Format::ICO->label());
        self::assertSame('WBMP',       Format::WBMP->label());
        self::assertSame('XBM',        Format::XBM->label());
        self::assertSame('JPEG 2000',  Format::JP2->label());
    }

    // =========================================================================
    // supportsTransparency
    // =========================================================================

    public function testSupportsTransparencyReturnsTrueForTransparentFormats(): void
    {
        self::assertTrue(Format::PNG->supportsTransparency());
        self::assertTrue(Format::WEBP->supportsTransparency());
        self::assertTrue(Format::AVIF->supportsTransparency());
        self::assertTrue(Format::GIF->supportsTransparency());
        self::assertTrue(Format::TIFF->supportsTransparency());
        self::assertTrue(Format::HEIC->supportsTransparency());
        self::assertTrue(Format::ICO->supportsTransparency());
    }

    public function testSupportsTransparencyReturnsFalseForOpaqueFormats(): void
    {
        self::assertFalse(Format::JPEG->supportsTransparency());
        self::assertFalse(Format::BMP->supportsTransparency());
        self::assertFalse(Format::WBMP->supportsTransparency());
        self::assertFalse(Format::XBM->supportsTransparency());
        self::assertFalse(Format::JP2->supportsTransparency());
    }

    // =========================================================================
    // supportsAnimation
    // =========================================================================

    public function testSupportsAnimationReturnsTrueForAnimatedFormats(): void
    {
        self::assertTrue(Format::GIF->supportsAnimation());
        self::assertTrue(Format::WEBP->supportsAnimation());
        self::assertTrue(Format::AVIF->supportsAnimation());
        self::assertTrue(Format::TIFF->supportsAnimation());
    }

    public function testSupportsAnimationReturnsFalseForStaticFormats(): void
    {
        self::assertFalse(Format::JPEG->supportsAnimation());
        self::assertFalse(Format::PNG->supportsAnimation());
        self::assertFalse(Format::HEIC->supportsAnimation());
        self::assertFalse(Format::BMP->supportsAnimation());
    }

    // =========================================================================
    // isLossy
    // =========================================================================

    public function testIsLossyReturnsTrueForLossyFormats(): void
    {
        self::assertTrue(Format::JPEG->isLossy());
        self::assertTrue(Format::WEBP->isLossy());
        self::assertTrue(Format::AVIF->isLossy());
        self::assertTrue(Format::GIF->isLossy());
        self::assertTrue(Format::HEIC->isLossy());
        self::assertTrue(Format::JP2->isLossy());
    }

    public function testIsLossyReturnsFalseForLosslessFormats(): void
    {
        self::assertFalse(Format::PNG->isLossy());
        self::assertFalse(Format::BMP->isLossy());
        self::assertFalse(Format::TIFF->isLossy());
        self::assertFalse(Format::ICO->isLossy());
        self::assertFalse(Format::WBMP->isLossy());
        self::assertFalse(Format::XBM->isLossy());
    }
}
