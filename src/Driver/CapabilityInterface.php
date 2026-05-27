<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Enum\Format;

/**
 * Contract for introspecting the capabilities of a driver at runtime.
 *
 * Consumers can call these methods before attempting an operation to decide
 * whether to proceed, fall back to another driver, or surface a clear error
 * to the end user — without relying on catching {@see \G\Image\Exception\UnsupportedOperationException}.
 */
interface CapabilityInterface
{
    /**
     * Return whether the driver can read and write the given format.
     *
     * @param Format $format The format to test.
     * @return bool True when the driver supports the format.
     */
    public function supportsFormat(Format $format): bool;

    /**
     * Return whether the driver implements the given operation.
     *
     * $operation is the exact method name as declared in the relevant interface
     * (e.g. {@see \G\Image\Operation\ResizableInterface::resize()}).
     * Examples: 'resize', 'blur', 'toAvif', 'getExif'.
     *
     * @param string $operation Method name of the operation to test.
     * @return bool True when the driver supports the operation.
     */
    public function supportsOperation(string $operation): bool;

    /**
     * Return the list of formats the driver can read and write.
     *
     * @return list<Format> Supported formats; may be empty for uninitialised drivers.
     */
    public function getSupportedFormats(): array;
}
