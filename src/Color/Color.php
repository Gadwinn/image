<?php

declare(strict_types=1);

namespace G\Image\Color;

use G\Image\Exception\InvalidColorException;

/**
 * Immutable value object representing an RGBA color.
 *
 * Internally stores:
 *   - $red, $green, $blue as integers (0–255)
 *   - $alpha as a float   (0.0 = fully transparent, 1.0 = fully opaque)
 *
 * All static factory methods validate their inputs and throw
 * {@see InvalidColorException} on failure.
 *
 * Supported color spaces: RGB, HSL (CSS), OKLCH (CSS Level 4).
 *
 * Quick usage:
 * @example
 *   Color::fromHex('#ff6600')
 *   Color::fromRgba(255, 102, 0, 0.5)
 *   Color::fromHsl(24, 100, 50)
 *   Color::fromOklch(0.65, 0.2, 40)
 *   Color::from('#f60')           // auto-detect hex
 *   Color::from('rgba(255,0,0,1)')// auto-detect CSS string
 *   Color::from([255, 102, 0])    // from array
 */
readonly class Color
{
    private const CHANNEL_MIN = 0;
    private const CHANNEL_MAX = 255;
    private const ALPHA_MIN   = 0.0;
    private const ALPHA_MAX   = 1.0;

    // Hex parsing
    private const HEX_RRGGBBAA_LENGTH = 8;

    // Color space input ranges
    private const HUE_MAX        = 360.0;
    private const PERCENTAGE_MAX = 100.0;

    // CSS keyword
    private const STRING_TRANSPARENT = 'transparent';

    // HSL algorithm constants
    private const HSL_LIGHTNESS_MID  = 0.5;
    private const HSL_SIXTH          = 1.0 / 6.0;
    private const HSL_THIRD          = 1.0 / 3.0;
    private const HSL_TWO_THIRDS     = 2.0 / 3.0;
    private const HSL_SEXTUPLE       = 6.0;
    private const HSL_DOUBLE         = 2.0;
    private const LMS_CUBE_EXPONENT  = 3;

    // OKLab → LMS matrix (Björn Ottosson)
    private const OKLAB_LMS_L_A =  0.3963377774;
    private const OKLAB_LMS_L_B =  0.2158037573;
    private const OKLAB_LMS_M_A = -0.1055613458;
    private const OKLAB_LMS_M_B = -0.0638541728;
    private const OKLAB_LMS_S_A = -0.0894841775;
    private const OKLAB_LMS_S_B = -1.2914855480;

    // Linear LMS → Linear sRGB matrix (Björn Ottosson)
    private const LMS_SRGB_R_L =  4.0767416621;
    private const LMS_SRGB_R_M = -3.3077115913;
    private const LMS_SRGB_R_S =  0.2309699292;
    private const LMS_SRGB_G_L = -1.2684380046;
    private const LMS_SRGB_G_M =  2.6097574011;
    private const LMS_SRGB_G_S = -0.3413193965;
    private const LMS_SRGB_B_L = -0.0041960863;
    private const LMS_SRGB_B_M = -0.7034186147;
    private const LMS_SRGB_B_S =  1.7076147010;

    // sRGB gamma transfer function (IEC 61966-2-1)
    private const SRGB_THRESHOLD     = 0.0031308;
    private const SRGB_LOW_SLOPE     = 12.92;
    private const SRGB_HIGH_SCALE    = 1.055;
    private const SRGB_HIGH_OFFSET   = 0.055;
    private const SRGB_GAMMA_EXPONENT = 1.0 / 2.4;

    /**
     * @param int   $red   Red channel   (0–255)
     * @param int   $green Green channel (0–255)
     * @param int   $blue  Blue channel  (0–255)
     * @param float $alpha Alpha channel (0.0–1.0, 1.0 = fully opaque)
     */
    private function __construct(
        public int $red,
        public int $green,
        public int $blue,
        public float $alpha,
    ) { //
    }

    // =========================================================================
    // Static factories
    // =========================================================================

    /**
     * Create a Color from a hexadecimal string (case-insensitive, leading # optional).
     *
     * Supported formats:
     *   - #rgb       3 digits shorthand  → expanded to #rrggbb
     *   - #rgba      4 digits shorthand  → expanded to #rrggbbaa
     *   - #rrggbb    6 digits            → fully opaque
     *   - #rrggbbaa  8 digits            → with alpha (00 = transparent, ff = opaque)
     *
     * @param string $hex Hexadecimal color string
     * @return self
     * @throws InvalidColorException When the string is not a valid hex color
     */
    public static function fromHex(string $hex): self
    {
        $hex = ltrim(trim($hex), '#');

        // Expand 3- or 4-digit shorthand (#rgb → rrggbb, #rgba → rrggbbaa)
        if (preg_match('/^[0-9a-f]{3,4}$/i', $hex)) {
            $hex = (string) preg_replace('/([0-9a-f])/i', '$1$1', $hex);
        }

        if (!preg_match('/^[0-9a-f]{6}(?:[0-9a-f]{2})?$/i', $hex)) {
            throw new InvalidColorException(
                sprintf('Invalid hexadecimal color string: "#%s".', $hex)
            );
        }

        $alpha = strlen($hex) === self::HEX_RRGGBBAA_LENGTH
            ? (int) hexdec(substr($hex, 6, 2)) / self::CHANNEL_MAX
            : 1.0;

        return new self(
            red: (int) hexdec(substr($hex, 0, 2)),
            green: (int) hexdec(substr($hex, 2, 2)),
            blue: (int) hexdec(substr($hex, 4, 2)),
            alpha: $alpha,
        );
    }

    /**
     * Create a fully opaque Color from individual RGB channels.
     *
     * @param int $red   0–255
     * @param int $green 0–255
     * @param int $blue  0–255
     * @return self
     * @throws InvalidColorException When a channel value is out of range
     */
    public static function fromRgb(int $red, int $green, int $blue): self
    {
        return self::fromRgba(red: $red, green: $green, blue: $blue, alpha: self::ALPHA_MAX);
    }

    /**
     * Create a Color from individual RGBA channels.
     *
     * @param int   $red   0–255
     * @param int   $green 0–255
     * @param int   $blue  0–255
     * @param float $alpha 0.0 (fully transparent) – 1.0 (fully opaque)
     * @return self
     * @throws InvalidColorException When any value is out of range
     */
    public static function fromRgba(int $red, int $green, int $blue, float $alpha): self
    {
        self::validateChannel(value: $red, name: 'red');
        self::validateChannel(value: $green, name: 'green');
        self::validateChannel(value: $blue, name: 'blue');
        self::validateAlpha($alpha);

        return new self(red: $red, green: $green, blue: $blue, alpha: $alpha);
    }

    /**
     * Create a fully opaque Color from HSL values (CSS convention).
     *
     * @param float $hue        0–360 degrees
     * @param float $saturation 0–100 percent
     * @param float $lightness  0–100 percent
     * @return self
     * @throws InvalidColorException When any value is out of range
     */
    public static function fromHsl(float $hue, float $saturation, float $lightness): self
    {
        return self::fromHsla(
            hue: $hue,
            saturation: $saturation,
            lightness: $lightness,
            alpha: 1.0,
        );
    }

    /**
     * Create a Color from HSLA values (CSS convention).
     *
     * @param float $hue        0–360 degrees
     * @param float $saturation 0–100 percent
     * @param float $lightness  0–100 percent
     * @param float $alpha      0.0 (fully transparent) – 1.0 (fully opaque)
     * @return self
     * @throws InvalidColorException When any value is out of range
     */
    public static function fromHsla(
        float $hue,
        float $saturation,
        float $lightness,
        float $alpha,
    ): self {
        self::validateRange($hue, 'hue', 0.0, self::HUE_MAX);
        self::validateRange($saturation, 'saturation', 0.0, self::PERCENTAGE_MAX);
        self::validateRange($lightness, 'lightness', 0.0, self::PERCENTAGE_MAX);
        self::validateAlpha($alpha);

        [$red, $green, $blue] = self::hslToRgb(
            hue: $hue,
            saturation: $saturation / self::PERCENTAGE_MAX,
            lightness: $lightness / self::PERCENTAGE_MAX,
        );

        return new self(red: $red, green: $green, blue: $blue, alpha: $alpha);
    }

    /**
     * Create a fully opaque Color from OKLCH values (CSS Color Level 4).
     *
     * OKLCH is a perceptually uniform color space. Out-of-sRGB-gamut values
     * are clamped per channel after conversion.
     *
     * @param float $lightness 0.0–1.0    (0 = black, 1 = white)
     * @param float $chroma    >= 0.0     (0 = gray, ~0.4 is the sRGB boundary for most hues)
     * @param float $hue       0–360 degrees
     * @return self
     * @throws InvalidColorException When any value is out of range
     */
    public static function fromOklch(float $lightness, float $chroma, float $hue): self
    {
        return self::fromOklcha(lightness: $lightness, chroma: $chroma, hue: $hue, alpha: 1.0);
    }

    /**
     * Create a Color from OKLCH values with an alpha channel (CSS Color Level 4).
     *
     * @param float $lightness 0.0–1.0
     * @param float $chroma    >= 0.0
     * @param float $hue       0–360 degrees
     * @param float $alpha     0.0 (fully transparent) – 1.0 (fully opaque)
     * @return self
     * @throws InvalidColorException When any value is out of range
     */
    public static function fromOklcha(
        float $lightness,
        float $chroma,
        float $hue,
        float $alpha,
    ): self {
        self::validateRange($lightness, 'lightness', 0.0, self::ALPHA_MAX);
        self::validateRange($chroma, 'chroma', 0.0);
        self::validateRange($hue, 'hue', 0.0, self::HUE_MAX);
        self::validateAlpha($alpha);

        [$red, $green, $blue] = self::oklchToRgb(
            lightness: $lightness,
            chroma: $chroma,
            hue: $hue,
        );

        return new self(red: $red, green: $green, blue: $blue, alpha: $alpha);
    }

    /**
     * Create a Color from a CSS named color, with optional alpha via slash syntax.
     *
     * Accepted formats:
     *   - 'red'          → fully opaque
     *   - 'red / 0.5'    → 50 % opacity (float)
     *   - 'red / 50%'    → 50 % opacity (percentage)
     *
     * @param string $name CSS color name with optional '/ alpha' suffix (case-insensitive)
     * @return self
     * @throws InvalidColorException When the name is unknown, the format is invalid, or alpha is out of range
     */
    public static function fromName(string $name): self
    {
        if (!preg_match('/^([a-z]+)(?:\s*\/\s*([\d.]+)%?)?$/i', strtolower(trim($name)), $m)) {
            throw new InvalidColorException(
                sprintf('Invalid color name format: "%s".', $name)
            );
        }

        $rgb = self::namedColors()[$m[1]] ?? null;

        if ($rgb === null) {
            throw new InvalidColorException(
                sprintf('Unknown CSS color name: "%s".', $m[1])
            );
        }

        // @phpstan-ignore notIdentical.alwaysTrue
        $alpha = isset($m[2]) && $m[2] !== ''
            ? (float) $m[2] / self::PERCENTAGE_MAX
            : self::ALPHA_MAX;

        self::validateAlpha($alpha);

        return new self(red: $rgb[0], green: $rgb[1], blue: $rgb[2], alpha: $alpha);
    }

    /**
     * Create a Color from any supported color representation (auto-detect).
     *
     * Accepted string formats:
     *   - 'transparent'
     *   - '#rgb', '#rgba', '#rrggbb', '#rrggbbaa'
     *   - 'rgb(r, g, b)'
     *   - 'rgba(r, g, b, a)'
     *   - 'hsl(h, s%, l%)'   or 'hsl(h, s, l)'
     *   - 'hsla(h, s%, l%, a)'
     *   - 'oklch(L C H)'     CSS4 space-separated
     *   - 'oklch(L C H / a)' CSS4 with alpha
     *
     * Accepted array formats:
     *   - [red, green, blue]
     *   - [red, green, blue, alpha]
     *
     * @param string|array<int|float> $color
     * @return self
     * @throws InvalidColorException When the value cannot be recognized or parsed
     */
    public static function from(string|array $color): self
    {
        if (is_array($color)) {
            return match (count($color)) {
                3 => self::fromRgb(
                    red: (int) $color[array_key_first($color)],
                    green: (int) $color[array_keys($color)[1]],
                    blue: (int) $color[array_keys($color)[2]],
                ),
                4 => self::fromRgba(
                    red: (int) $color[array_key_first($color)],
                    green: (int) $color[array_keys($color)[1]],
                    blue: (int) $color[array_keys($color)[2]],
                    alpha: (float) $color[array_keys($color)[3]],
                ),
                default => throw new InvalidColorException(
                    sprintf('Color array must have 3 or 4 elements, got %d.', count($color))
                ),
            };
        }

        $color = trim($color);

        if (strtolower($color) === self::STRING_TRANSPARENT) {
            return self::transparent();
        }

        if (str_starts_with($color, '#')) {
            return self::fromHex($color);
        }

        // rgb(r, g, b) or rgba(r, g, b, a)
        if (
            preg_match(
                '/^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i',
                $color,
                $m
            )
        ) {
            return self::fromRgba(
                red: (int) $m[1],
                green: (int) $m[2],
                blue: (int) $m[3],
                alpha: isset($m[4]) ? (float) $m[4] : 1.0,
            );
        }

        // hsl(h, s%, l%)  or  hsla(h, s%, l%, a)
        if (
            preg_match(
                '/^hsla?\(\s*([\d.]+)\s*,\s*([\d.]+)%?\s*,\s*([\d.]+)%?\s*(?:,\s*([\d.]+)\s*)?\)$/i',
                $color,
                $m,
            )
        ) {
            return self::fromHsla(
                hue: (float) $m[1],
                saturation: (float) $m[2],
                lightness: (float) $m[3],
                alpha: isset($m[4]) ? (float) $m[4] : 1.0,
            );
        }

        // oklch(L C H)  or  oklch(L C H / a)  — CSS4 space-separated syntax
        // also accepts oklcha(L C H / a) for explicitness
        if (
            preg_match(
                '/^oklcha?\(\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+))?\s*\)$/i',
                $color,
                $m,
            )
        ) {
            return self::fromOklcha(
                lightness: (float) $m[1],
                chroma: (float) $m[2],
                hue: (float) $m[3],
                alpha: isset($m[4]) ? (float) $m[4] : 1.0,
            );
        }

        // CSS named color, with optional alpha: 'red', 'cornflowerblue', 'red/50%', 'blue / 0.5'
        if (preg_match('/^[a-z]+(?:\s*\/\s*[\d.]+%?)?$/i', $color)) {
            return self::fromName($color);
        }

        throw new InvalidColorException(
            sprintf('Cannot auto-detect color format from value: "%s".', $color)
        );
    }

    // =========================================================================
    // Predefined colors
    // =========================================================================

    /**
     * Fully transparent black: rgba(0, 0, 0, 0.0).
     *
     * @return self
     */
    public static function transparent(): self
    {
        return new self(red: 0, green: 0, blue: 0, alpha: 0.0);
    }

    /**
     * Fully opaque black: #000000.
     *
     * @return self
     */
    public static function black(): self
    {
        return new self(red: 0, green: 0, blue: 0, alpha: 1.0);
    }

    /**
     * Fully opaque white: #ffffff.
     *
     * @return self
     */
    public static function white(): self
    {
        return new self(red: 255, green: 255, blue: 255, alpha: 1.0);
    }

    // =========================================================================
    // Output / conversion
    // =========================================================================

    /**
     * Returns the CSS rgba() string representation: 'rgba(r, g, b, a)'.
     *
     * @return string
     */
    public function __toString(): string
    {
        return sprintf('rgba(%d, %d, %d, %G)', $this->red, $this->green, $this->blue, $this->alpha);
    }

    /**
     * Returns the 6-digit hex string (#rrggbb). Alpha is NOT included.
     *
     * @return string e.g. '#ff6600'
     */
    public function toHex(): string
    {
        return sprintf('#%02x%02x%02x', $this->red, $this->green, $this->blue);
    }

    /**
     * Returns the 8-digit hex string (#rrggbbaa) including the alpha channel.
     *
     * Alpha 1.0 → 'ff' (fully opaque), 0.0 → '00' (fully transparent).
     *
     * @return string e.g. '#ff660080'
     */
    public function toHexAlpha(): string
    {
        return sprintf(
            '#%02x%02x%02x%02x',
            $this->red,
            $this->green,
            $this->blue,
            (int) round($this->alpha * self::CHANNEL_MAX),
        );
    }

    /**
     * Returns a new Color with the given alpha value, keeping RGB channels intact.
     *
     * @param float $alpha 0.0–1.0
     * @return self
     * @throws InvalidColorException When alpha is out of range
     */
    public function withAlpha(float $alpha): self
    {
        self::validateAlpha($alpha);

        return new self(
            red: $this->red,
            green: $this->green,
            blue: $this->blue,
            alpha: $alpha,
        );
    }

    /**
     * Returns true when the alpha channel is exactly 0.0 (fully transparent).
     *
     * @return bool
     */
    public function isTransparent(): bool
    {
        return $this->alpha === self::ALPHA_MIN;
    }

    /**
     * Returns true when the alpha channel is exactly 1.0 (fully opaque).
     *
     * @return bool
     */
    public function isOpaque(): bool
    {
        return $this->alpha === self::ALPHA_MAX;
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    private static function namedColors(): array
    {
        return [
            'aliceblue' => [240, 248, 255],
            'antiquewhite' => [250, 235, 215],
            'aqua' => [0, 255, 255],
            'aquamarine' => [127, 255, 212],
            'azure' => [240, 255, 255],
            'beige' => [245, 245, 220],
            'bisque' => [255, 228, 196],
            'black' => [0, 0, 0],
            'blanchedalmond' => [255, 235, 205],
            'blue' => [0, 0, 255],
            'blueviolet' => [138, 43, 226],
            'brown' => [165, 42, 42],
            'burlywood' => [222, 184, 135],
            'cadetblue' => [95, 158, 160],
            'chartreuse' => [127, 255, 0],
            'chocolate' => [210, 105, 30],
            'coral' => [255, 127, 80],
            'cornflowerblue' => [100, 149, 237],
            'cornsilk' => [255, 248, 220],
            'crimson' => [220, 20, 60],
            'cyan' => [0, 255, 255],
            'darkblue' => [0, 0, 139],
            'darkcyan' => [0, 139, 139],
            'darkgoldenrod' => [184, 134, 11],
            'darkgray' => [169, 169, 169],
            'darkgreen' => [0, 100, 0],
            'darkgrey' => [169, 169, 169],
            'darkkhaki' => [189, 183, 107],
            'darkmagenta' => [139, 0, 139],
            'darkolivegreen' => [85, 107, 47],
            'darkorange' => [255, 140, 0],
            'darkorchid' => [153, 50, 204],
            'darkred' => [139, 0, 0],
            'darksalmon' => [233, 150, 122],
            'darkseagreen' => [143, 188, 143],
            'darkslateblue' => [72, 61, 139],
            'darkslategray' => [47, 79, 79],
            'darkslategrey' => [47, 79, 79],
            'darkturquoise' => [0, 206, 209],
            'darkviolet' => [148, 0, 211],
            'deeppink' => [255, 20, 147],
            'deepskyblue' => [0, 191, 255],
            'dimgray' => [105, 105, 105],
            'dimgrey' => [105, 105, 105],
            'dodgerblue' => [30, 144, 255],
            'firebrick' => [178, 34, 34],
            'floralwhite' => [255, 250, 240],
            'forestgreen' => [34, 139, 34],
            'fuchsia' => [255, 0, 255],
            'gainsboro' => [220, 220, 220],
            'ghostwhite' => [248, 248, 255],
            'gold' => [255, 215, 0],
            'goldenrod' => [218, 165, 32],
            'gray' => [128, 128, 128],
            'green' => [0, 128, 0],
            'greenyellow' => [173, 255, 47],
            'grey' => [128, 128, 128],
            'honeydew' => [240, 255, 240],
            'hotpink' => [255, 105, 180],
            'indianred' => [205, 92, 92],
            'indigo' => [75, 0, 130],
            'ivory' => [255, 255, 240],
            'khaki' => [240, 230, 140],
            'lavender' => [230, 230, 250],
            'lavenderblush' => [255, 240, 245],
            'lawngreen' => [124, 252, 0],
            'lemonchiffon' => [255, 250, 205],
            'lightblue' => [173, 216, 230],
            'lightcoral' => [240, 128, 128],
            'lightcyan' => [224, 255, 255],
            'lightgoldenrodyellow' => [250, 250, 210],
            'lightgray' => [211, 211, 211],
            'lightgreen' => [144, 238, 144],
            'lightgrey' => [211, 211, 211],
            'lightpink' => [255, 182, 193],
            'lightsalmon' => [255, 160, 122],
            'lightseagreen' => [32, 178, 170],
            'lightskyblue' => [135, 206, 250],
            'lightslategray' => [119, 136, 153],
            'lightslategrey' => [119, 136, 153],
            'lightsteelblue' => [176, 196, 222],
            'lightyellow' => [255, 255, 224],
            'lime' => [0, 255, 0],
            'limegreen' => [50, 205, 50],
            'linen' => [250, 240, 230],
            'magenta' => [255, 0, 255],
            'maroon' => [128, 0, 0],
            'mediumaquamarine' => [102, 205, 170],
            'mediumblue' => [0, 0, 205],
            'mediumorchid' => [186, 85, 211],
            'mediumpurple' => [147, 112, 219],
            'mediumseagreen' => [60, 179, 113],
            'mediumslateblue' => [123, 104, 238],
            'mediumspringgreen' => [0, 250, 154],
            'mediumturquoise' => [72, 209, 204],
            'mediumvioletred' => [199, 21, 133],
            'midnightblue' => [25, 25, 112],
            'mintcream' => [245, 255, 250],
            'mistyrose' => [255, 228, 225],
            'moccasin' => [255, 228, 181],
            'navajowhite' => [255, 222, 173],
            'navy' => [0, 0, 128],
            'oldlace' => [253, 245, 230],
            'olive' => [128, 128, 0],
            'olivedrab' => [107, 142, 35],
            'orange' => [255, 165, 0],
            'orangered' => [255, 69, 0],
            'orchid' => [218, 112, 214],
            'palegoldenrod' => [238, 232, 170],
            'palegreen' => [152, 251, 152],
            'paleturquoise' => [175, 238, 238],
            'palevioletred' => [219, 112, 147],
            'papayawhip' => [255, 239, 213],
            'peachpuff' => [255, 218, 185],
            'peru' => [205, 133, 63],
            'pink' => [255, 192, 203],
            'plum' => [221, 160, 221],
            'powderblue' => [176, 224, 230],
            'purple' => [128, 0, 128],
            'rebeccapurple' => [102, 51, 153],
            'red' => [255, 0, 0],
            'rosybrown' => [188, 143, 143],
            'royalblue' => [65, 105, 225],
            'saddlebrown' => [139, 69, 19],
            'salmon' => [250, 128, 114],
            'sandybrown' => [244, 164, 96],
            'seagreen' => [46, 139, 87],
            'seashell' => [255, 245, 238],
            'sienna' => [160, 82, 45],
            'silver' => [192, 192, 192],
            'skyblue' => [135, 206, 235],
            'slateblue' => [106, 90, 205],
            'slategray' => [112, 128, 144],
            'slategrey' => [112, 128, 144],
            'snow' => [255, 250, 250],
            'springgreen' => [0, 255, 127],
            'steelblue' => [70, 130, 180],
            'tan' => [210, 180, 140],
            'teal' => [0, 128, 128],
            'thistle' => [216, 191, 216],
            'tomato' => [255, 99, 71],
            'turquoise' => [64, 224, 208],
            'violet' => [238, 130, 238],
            'wheat' => [245, 222, 179],
            'white' => [255, 255, 255],
            'whitesmoke' => [245, 245, 245],
            'yellow' => [255, 255, 0],
            'yellowgreen' => [154, 205, 50],
        ];
    }

    /**
     * @throws InvalidColorException
     */
    private static function validateRange(float $value, string $name, float $min, ?float $max = null): void
    {
        if ($max === null) {
            if ($value < $min) {
                throw new InvalidColorException(
                    sprintf('"%s" must be >= %g, got %g.', $name, $min, $value)
                );
            }
            return;
        }

        if ($value < $min || $max < $value) {
            throw new InvalidColorException(
                sprintf('"%s" must be between %g and %g, got %g.', $name, $min, $max, $value)
            );
        }
    }

    /**
     * @throws InvalidColorException
     */
    private static function validateChannel(int $value, string $name): void
    {
        self::validateRange((float) $value, $name, self::CHANNEL_MIN, self::CHANNEL_MAX);
    }

    /**
     * @throws InvalidColorException
     */
    private static function validateAlpha(float $alpha): void
    {
        self::validateRange($alpha, 'alpha', self::ALPHA_MIN, self::ALPHA_MAX);
    }

    /**
     * Convert HSL (H: 0–360, S: 0–1, L: 0–1) to RGB (0–255 each).
     *
     * @param float $hue        0–360
     * @param float $saturation 0–1
     * @param float $lightness  0–1
     * @return array{0: int, 1: int, 2: int}
     */
    private static function hslToRgb(float $hue, float $saturation, float $lightness): array
    {
        if ($saturation === 0.0) {
            $gray = (int) round($lightness * self::CHANNEL_MAX);
            return [$gray, $gray, $gray];
        }

        $q = $lightness < self::HSL_LIGHTNESS_MID
            ? $lightness * (self::ALPHA_MAX + $saturation)
            : $lightness + $saturation - ($lightness * $saturation);
        $p = self::HSL_DOUBLE * $lightness - $q;
        $h = $hue / self::HUE_MAX;

        $hue2rgb = static function (float $p, float $q, float $t): float {
            if ($t < 0.0) {
                $t += 1.0;
            }
            if ($t > self::ALPHA_MAX) {
                $t -= 1.0;
            }
            if ($t < self::HSL_SIXTH) {
                return $p + ($q - $p) * self::HSL_SEXTUPLE * $t;
            }
            if ($t < self::HSL_LIGHTNESS_MID) {
                return $q;
            }
            if ($t < self::HSL_TWO_THIRDS) {
                return $p + ($q - $p) * (self::HSL_TWO_THIRDS - $t) * self::HSL_SEXTUPLE;
            }
            return $p;
        };

        return [
            (int) round($hue2rgb($p, $q, $h + self::HSL_THIRD) * self::CHANNEL_MAX),
            (int) round($hue2rgb($p, $q, $h) * self::CHANNEL_MAX),
            (int) round($hue2rgb($p, $q, $h - self::HSL_THIRD) * self::CHANNEL_MAX),
        ];
    }

    /**
     * Convert OKLCH (L: 0–1, C: >=0, H: 0–360°) to sRGB (0–255 each).
     *
     * Follows Björn Ottosson's reference matrices.
     * Out-of-sRGB-gamut values are clamped per channel.
     *
     * @param float $lightness 0–1
     * @param float $chroma    >= 0
     * @param float $hue       0–360 degrees
     * @return array{0: int, 1: int, 2: int}
     */
    private static function oklchToRgb(float $lightness, float $chroma, float $hue): array
    {
        $hueRad = deg2rad($hue);

        // OKLCH → OKLab
        $a = $chroma * cos($hueRad);
        $b = $chroma * sin($hueRad);
        $l = $lightness;

        // OKLab → LMS (cube-root space intermediate)
        $lmsL = $l + self::OKLAB_LMS_L_A * $a + self::OKLAB_LMS_L_B * $b;
        $lmsM = $l + self::OKLAB_LMS_M_A * $a + self::OKLAB_LMS_M_B * $b;
        $lmsS = $l + self::OKLAB_LMS_S_A * $a + self::OKLAB_LMS_S_B * $b;

        // Cube to get linear LMS
        $lmsL3 = $lmsL ** self::LMS_CUBE_EXPONENT;
        $lmsM3 = $lmsM ** self::LMS_CUBE_EXPONENT;
        $lmsS3 = $lmsS ** self::LMS_CUBE_EXPONENT;

        // Linear LMS → Linear sRGB
        $rLinear = self::LMS_SRGB_R_L * $lmsL3 + self::LMS_SRGB_R_M * $lmsM3 + self::LMS_SRGB_R_S * $lmsS3;
        $gLinear = self::LMS_SRGB_G_L * $lmsL3 + self::LMS_SRGB_G_M * $lmsM3 + self::LMS_SRGB_G_S * $lmsS3;
        $bLinear = self::LMS_SRGB_B_L * $lmsL3 + self::LMS_SRGB_B_M * $lmsM3 + self::LMS_SRGB_B_S * $lmsS3;

        // Linear sRGB → gamma-corrected sRGB, clamped to [0, 1], scaled to [0, 255]
        $r = (int) round(max(self::ALPHA_MIN, min(self::ALPHA_MAX, self::srgbGamma($rLinear))) * self::CHANNEL_MAX);
        $g = (int) round(max(self::ALPHA_MIN, min(self::ALPHA_MAX, self::srgbGamma($gLinear))) * self::CHANNEL_MAX);
        $b = (int) round(max(self::ALPHA_MIN, min(self::ALPHA_MAX, self::srgbGamma($bLinear))) * self::CHANNEL_MAX);

        return [$r, $g, $b];
    }

    /**
     * Apply the sRGB gamma transfer function to a single linear-light channel.
     *
     * @param float $linear Linear-light value (may be negative or > 1 for out-of-gamut)
     * @return float Gamma-encoded value
     */
    private static function srgbGamma(float $linear): float
    {
        if ($linear <= self::SRGB_THRESHOLD) {
            return self::SRGB_LOW_SLOPE * $linear;
        }

        return self::SRGB_HIGH_SCALE * ($linear ** self::SRGB_GAMMA_EXPONENT) - self::SRGB_HIGH_OFFSET;
    }
}
