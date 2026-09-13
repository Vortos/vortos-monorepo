<?php

declare(strict_types=1);

namespace Vortos\Foundation\Process;

use RuntimeException;

/** A launch was refused or could not happen. Messages name the program only — never its arguments. */
final class ProcessLaunchException extends RuntimeException
{
    public static function couldNotStart(string $program): self
    {
        return new self(sprintf('Could not start process "%s".', $program));
    }

    public static function secretOnArgv(string $program, int $position): self
    {
        return new self(sprintf(
            'Refused to start "%s": argument %d contains a declared secret. Deliver it by environment, stdin or SensitiveFile — argv is readable by every local user.',
            $program,
            $position,
        ));
    }

    public static function secretFile(string $reason): self
    {
        return new self('Could not materialise a sensitive file for a child process: ' . $reason);
    }

    public static function unsupported(string $reason): self
    {
        return new self($reason);
    }
}
