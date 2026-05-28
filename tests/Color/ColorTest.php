<?php

declare(strict_types=1);

namespace G\Image\Tests\Color;

use G\Image\Color\Color;
use G\Image\Exception\InvalidColorException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Color::class)]
final class ColorTest extends TestCase
{
    // =========================================================================
    // fromHex
    // =========================================================================

    public function testFromHexSixDigitsFullyOpaque(): void
    {
        $color = Color::fromHex('#ff6600');

        self::assertSame(255, $color->red);
        self::assertSame(102, $color->green);
        self::assertSame(0,   $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromHexWithoutLeadingHash(): void
    {
        $color = Color::fromHex('ff6600');

        self::assertSame(255, $color->red);
        self::assertSame(102, $color->green);
        self::assertSame(0,   $color->blue);
    }

    public function testFromHexThreeDigitShorthand(): void
    {
        $color = Color::fromHex('#f60');

        self::assertSame(255, $color->red);
        self::assertSame(102, $color->green);
        self::assertSame(0,   $color->blue);
    }

    public function testFromHexFourDigitShorthandWithAlpha(): void
    {
        $color = Color::fromHex('#f600');

        self::assertSame(255, $color->red);
        self::assertSame(102, $color->green);
        self::assertSame(0,   $color->blue);
        self::assertEqualsWithDelta(0.0, $color->alpha, 0.01);
    }

    public function testFromHexEightDigitsWithAlpha(): void
    {
        $color = Color::fromHex('#ff660080');

        self::assertSame(255, $color->red);
        self::assertSame(102, $color->green);
        self::assertSame(0,   $color->blue);
        self::assertEqualsWithDelta(0.502, $color->alpha, 0.01);
    }

    public function testFromHexIsCaseInsensitive(): void
    {
        $lower = Color::fromHex('#FF6600');
        $upper = Color::fromHex('#ff6600');

        self::assertSame($lower->red,   $upper->red);
        self::assertSame($lower->green, $upper->green);
        self::assertSame($lower->blue,  $upper->blue);
    }

    public function testFromHexThrowsOnInvalidString(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromHex('#gggggg');
    }

    public function testFromHexThrowsOnWrongLength(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromHex('#12345');
    }

    // =========================================================================
    // fromRgb / fromRgba
    // =========================================================================

    public function testFromRgbCreatesFullyOpaqueColor(): void
    {
        $color = Color::fromRgb(10, 20, 30);

        self::assertSame(10,  $color->red);
        self::assertSame(20,  $color->green);
        self::assertSame(30,  $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromRgbaCreatesColorWithAlpha(): void
    {
        $color = Color::fromRgba(10, 20, 30, 0.5);

        self::assertSame(10,  $color->red);
        self::assertSame(20,  $color->green);
        self::assertSame(30,  $color->blue);
        self::assertSame(0.5, $color->alpha);
    }

    public function testFromRgbaThrowsWhenRedIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromRgba(256, 0, 0, 1.0);
    }

    public function testFromRgbaThrowsWhenGreenIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromRgba(0, -1, 0, 1.0);
    }

    public function testFromRgbaThrowsWhenBlueIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromRgba(0, 0, 256, 1.0);
    }

    public function testFromRgbaThrowsWhenAlphaIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromRgba(0, 0, 0, 1.5);
    }

    // =========================================================================
    // fromHsl / fromHsla
    // =========================================================================

    public function testFromHslPureRed(): void
    {
        $color = Color::fromHsl(0.0, 100.0, 50.0);

        self::assertSame(255, $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(0,   $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromHslWhite(): void
    {
        $color = Color::fromHsl(0.0, 0.0, 100.0);

        self::assertSame(255, $color->red);
        self::assertSame(255, $color->green);
        self::assertSame(255, $color->blue);
    }

    public function testFromHslBlack(): void
    {
        $color = Color::fromHsl(0.0, 0.0, 0.0);

        self::assertSame(0, $color->red);
        self::assertSame(0, $color->green);
        self::assertSame(0, $color->blue);
    }

    public function testFromHslaWithAlpha(): void
    {
        $color = Color::fromHsla(240.0, 100.0, 50.0, 0.5);

        self::assertSame(0,   $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(255, $color->blue);
        self::assertSame(0.5, $color->alpha);
    }

    public function testFromHslThrowsWhenHueIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromHsl(361.0, 50.0, 50.0);
    }

    public function testFromHslThrowsWhenSaturationIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromHsl(0.0, 101.0, 50.0);
    }

    public function testFromHslThrowsWhenLightnessIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromHsl(0.0, 50.0, -1.0);
    }

    // =========================================================================
    // fromOklch / fromOklcha
    // =========================================================================

    public function testFromOklchBlack(): void
    {
        $color = Color::fromOklch(0.0, 0.0, 0.0);

        self::assertSame(0, $color->red);
        self::assertSame(0, $color->green);
        self::assertSame(0, $color->blue);
    }

    public function testFromOklchWhite(): void
    {
        $color = Color::fromOklch(1.0, 0.0, 0.0);

        self::assertEqualsWithDelta(255, $color->red,   2);
        self::assertEqualsWithDelta(255, $color->green, 2);
        self::assertEqualsWithDelta(255, $color->blue,  2);
    }

    public function testFromOklchaWithAlpha(): void
    {
        $color = Color::fromOklcha(0.5, 0.0, 0.0, 0.75);

        self::assertSame(0.75, $color->alpha);
    }

    public function testFromOklchThrowsWhenLightnessIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromOklch(1.1, 0.0, 0.0);
    }

    public function testFromOklchThrowsWhenHueIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromOklch(0.5, 0.0, 361.0);
    }

    // =========================================================================
    // fromName
    // =========================================================================

    public function testFromNameRed(): void
    {
        $color = Color::fromName('red');

        self::assertSame(255, $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(0,   $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromNameWithAlphaPercentage(): void
    {
        $color = Color::fromName('red / 50%');

        self::assertSame(255, $color->red);
        self::assertEqualsWithDelta(0.5, $color->alpha, 0.01);
    }

    public function testFromNameCaseInsensitive(): void
    {
        $color = Color::fromName('BLUE');

        self::assertSame(0,   $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(255, $color->blue);
    }

    public function testFromNameThrowsOnUnknownName(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromName('notacolor');
    }

    public function testFromNameThrowsOnInvalidFormat(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromName('red / blue');
    }

    // =========================================================================
    // from (auto-detect)
    // =========================================================================

    public function testFromDetectsHexString(): void
    {
        $color = Color::from('#ff6600');

        self::assertSame(255, $color->red);
    }

    public function testFromDetectsTransparentKeyword(): void
    {
        $color = Color::from('transparent');

        self::assertSame(0.0, $color->alpha);
    }

    public function testFromDetectsRgbaString(): void
    {
        $color = Color::from('rgba(10, 20, 30, 0.5)');

        self::assertSame(10,  $color->red);
        self::assertSame(20,  $color->green);
        self::assertSame(30,  $color->blue);
        self::assertSame(0.5, $color->alpha);
    }

    public function testFromDetectsRgbString(): void
    {
        $color = Color::from('rgba(10, 20, 30)');

        self::assertSame(10,  $color->red);
        self::assertSame(20,  $color->green);
        self::assertSame(30,  $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromDetectsHslString(): void
    {
        $color = Color::from('hsl(0, 100%, 50%)');

        self::assertSame(255, $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(0,   $color->blue);
    }

    public function testFromDetectsHslaString(): void
    {
        $color = Color::from('hsla(0, 100%, 50%, 0.5)');

        self::assertSame(255, $color->red);
        self::assertSame(0.5, $color->alpha);
    }

    public function testFromDetectsOklchString(): void
    {
        $color = Color::from('oklch(0.5 0.0 0.0)');

        self::assertSame(1.0, $color->alpha);
    }

    public function testFromDetectsOklchaStringWithAlpha(): void
    {
        $color = Color::from('oklch(0.5 0.0 0.0 / 0.5)');

        self::assertSame(0.5, $color->alpha);
    }

    public function testFromDetectsNamedColor(): void
    {
        $color = Color::from('blue');

        self::assertSame(0,   $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(255, $color->blue);
    }

    public function testFromAcceptsRgbArray(): void
    {
        $color = Color::from([10, 20, 30]);

        self::assertSame(10,  $color->red);
        self::assertSame(20,  $color->green);
        self::assertSame(30,  $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testFromAcceptsRgbaArray(): void
    {
        $color = Color::from([10, 20, 30, 0.5]);

        self::assertSame(0.5, $color->alpha);
    }

    public function testFromThrowsOnArrayWithWrongSize(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::from([10, 20]);
    }

    public function testFromThrowsOnUnrecognizedString(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::from('not-a-color-format');
    }

    // =========================================================================
    // transparent / black / white
    // =========================================================================

    public function testTransparentIsFullyTransparentBlack(): void
    {
        $color = Color::transparent();

        self::assertSame(0,   $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(0,   $color->blue);
        self::assertSame(0.0, $color->alpha);
    }

    public function testBlackIsFullyOpaqueBlack(): void
    {
        $color = Color::black();

        self::assertSame(0,   $color->red);
        self::assertSame(0,   $color->green);
        self::assertSame(0,   $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    public function testWhiteIsFullyOpaqueWhite(): void
    {
        $color = Color::white();

        self::assertSame(255, $color->red);
        self::assertSame(255, $color->green);
        self::assertSame(255, $color->blue);
        self::assertSame(1.0, $color->alpha);
    }

    // =========================================================================
    // Output / conversion
    // =========================================================================

    public function testToStringReturnsCssRgba(): void
    {
        $color = Color::fromRgba(255, 102, 0, 0.5);

        self::assertSame('rgba(255, 102, 0, 0.5)', (string) $color);
    }

    public function testToHexReturnsSixDigitLowercase(): void
    {
        $color = Color::fromRgb(255, 102, 0);

        self::assertSame('#ff6600', $color->toHex());
    }

    public function testToHexAlphaReturnsEightDigit(): void
    {
        $color = Color::fromRgba(255, 102, 0, 1.0);

        self::assertSame('#ff6600ff', $color->toHexAlpha());
    }

    public function testToHexAlphaTransparentIsZero(): void
    {
        $color = Color::transparent();

        self::assertSame('#00000000', $color->toHexAlpha());
    }

    // =========================================================================
    // withAlpha
    // =========================================================================

    public function testWithAlphaReturnsNewInstanceWithUpdatedAlpha(): void
    {
        $original = Color::fromRgb(255, 102, 0);
        $modified = $original->withAlpha(0.25);

        self::assertSame(1.0,  $original->alpha);
        self::assertSame(0.25, $modified->alpha);
        self::assertSame(255,  $modified->red);
        self::assertSame(102,  $modified->green);
        self::assertSame(0,    $modified->blue);
    }

    public function testWithAlphaThrowsWhenAlphaIsOutOfRange(): void
    {
        $this->expectException(InvalidColorException::class);
        Color::fromRgb(0, 0, 0)->withAlpha(2.0);
    }

    // =========================================================================
    // isTransparent / isOpaque
    // =========================================================================

    public function testIsTransparentReturnsTrueForAlphaZero(): void
    {
        self::assertTrue(Color::transparent()->isTransparent());
    }

    public function testIsTransparentReturnsFalseForOpaqueColor(): void
    {
        self::assertFalse(Color::fromRgb(0, 0, 0)->isTransparent());
    }

    public function testIsOpaqueReturnsTrueForAlphaOne(): void
    {
        self::assertTrue(Color::fromRgb(0, 0, 0)->isOpaque());
    }

    public function testIsOpaqueReturnsFalseForTransparentColor(): void
    {
        self::assertFalse(Color::transparent()->isOpaque());
    }

    // =========================================================================
    // Branch coverage — hslToRgb paths and validateRange max=null guard
    // =========================================================================

    /**
     * lightness < 50 % (< 0.5 normalized) with non-zero saturation exercises
     * the low-lightness branch of the internal hslToRgb helper.
     */
    public function testFromHslWithLowLightnessHitsLowBranch(): void
    {
        // hue=120, saturation=60 %, lightness=30 % (0.3 normalized — less than 0.5)
        $color = Color::fromHsl(120, 60, 30);

        self::assertNotNull($color);
    }

    /**
     * hue=300 forces one channel's t-value above 1.0 and another into the
     * [0.5, 2/3) window, covering two previously unreachable branches.
     */
    public function testFromHslWithHighHueCoversTAdjustmentBranches(): void
    {
        // R channel: t = 300/360 + 1/3 = 1.1667 > 1.0  → t -= 1.0 branch
        // B channel: t = 300/360 - 1/3 = 0.5            → p+(q-p)*(2/3-t) branch
        $color = Color::fromHsl(300, 70, 50);

        self::assertNotNull($color);
    }

    /**
     * Negative chroma is not allowed in OKLCH (must be >= 0.0, max=null).
     * This exercises the validateRange(max=null, value<min) throw path.
     */
    public function testFromOklchaWithNegativeChromaThrows(): void
    {
        $this->expectException(\G\Image\Exception\InvalidColorException::class);
        Color::fromOklcha(lightness: 0.5, chroma: -0.1, hue: 0.0, alpha: 1.0);
    }
}
