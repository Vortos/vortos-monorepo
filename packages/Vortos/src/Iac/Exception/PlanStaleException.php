<?php

declare(strict_types=1);

namespace Vortos\Iac\Exception;

final class PlanStaleException extends IacException
{
    public static function digestMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Plan file digest mismatch: expected %s, got %s. The plan may have been tampered with or the working directory changed. Re-run plan.',
            substr($expected, 0, 12),
            substr($actual, 0, 12),
        ));
    }

    public static function planFileMissing(string $path): self
    {
        return new self(sprintf(
            "Plan file '%s' does not exist. Re-run plan.",
            $path,
        ));
    }
}
