<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

use Throwable;

/**
 * Raised when a dump cannot be produced or fully streamed.
 *
 * A partial dump is never cataloged as a good backup: the runner aborts the upload
 * and surfaces this so the failure is loud, not silently recorded as success.
 */
final class DumpFailedException extends BackupException
{
    public static function process(string $engine, int $exitCode, string $stderr, ?Throwable $previous = null): self
    {
        $detail = $stderr === '' ? '' : ': ' . self::truncate($stderr);

        return new self(
            sprintf("Backup dump for engine '%s' failed (exit code %d)%s", $engine, $exitCode, $detail),
            0,
            $previous,
        );
    }

    public static function missingBinary(string $engine, string $binary): self
    {
        return new self(sprintf(
            "Backup dump for engine '%s' cannot run: required binary '%s' was not found on PATH.",
            $engine,
            $binary,
        ));
    }

    public static function reason(string $reason): self
    {
        return new self($reason);
    }

    private static function truncate(string $value, int $max = 2000): string
    {
        $value = trim($value);

        return strlen($value) <= $max ? $value : substr($value, 0, $max) . '… (truncated)';
    }
}
