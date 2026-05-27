<?php

declare(strict_types=1);

namespace G\Image\Exception;

/**
 * Thrown when a color value cannot be parsed or is semantically invalid.
 *
 * Common causes:
 *   - malformed hexadecimal string (e.g. '#gghhii', '#12345')
 *   - channel values out of range (e.g. RGB value > 255)
 *   - unrecognized color syntax passed to {@see \G\Image\Color\Color::from()}
 *   - oklch/hsl values outside their valid domains
 */
final class InvalidColorException extends ImageLogicException
{
}
