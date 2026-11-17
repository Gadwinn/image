<?php

declare(strict_types=1);

namespace G\Image\Driver;

/**
 * Wraps native PHP calls that may emit an E_WARNING or E_NOTICE on failure,
 * capturing the message instead of leaking it to the caller's error handler.
 *
 * Important: capturing a warning does NOT turn it into a failure. A captured
 * message is only ever attached to an exception that the call site was
 * already going to throw based on the native call's own failure signal
 * (return value of false, etc.). When the native call succeeds, the captured
 * message — if any — is simply discarded. This matters for calls like
 * `exif_read_data()` that routinely warn on inputs that are not errors
 * (e.g. an image with no EXIF data): no new failure path is introduced here.
 */
trait CapturesNativeWarnings
{
    /**
     * Execute $operation with a temporary error handler capturing the last
     * warning/notice message raised during the call.
     *
     * @template T
     * @param callable(): T $operation
     * @return array{0: T, 1: ?string} [result, capturedMessage]
     */
    private function captureWarning(callable $operation): array
    {
        $captured = null;

        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured = $errstr;

            return true;
        });

        try {
            $result = $operation();
        } finally {
            restore_error_handler();
        }

        return [$result, $captured];
    }
}
