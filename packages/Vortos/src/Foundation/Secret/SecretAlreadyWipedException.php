<?php

declare(strict_types=1);

namespace Vortos\Foundation\Secret;

use RuntimeException;

/**
 * Raised by {@see \Vortos\Foundation\Secret\SecretValue} when {@see reveal()} or
 * {@see equals()} is called after {@see wipe()} — fail-closed, never a silent
 * empty string.
 */
final class SecretAlreadyWipedException extends RuntimeException
{
    public static function create(): self
    {
        return new self('SecretValue has been wiped and can no longer be revealed.');
    }
}
