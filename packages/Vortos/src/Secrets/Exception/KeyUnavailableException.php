<?php

declare(strict_types=1);

namespace Vortos\Secrets\Exception;

use RuntimeException;

/**
 * Raised when the off-host key identity is missing, malformed, or unreachable.
 * Fail-closed: a missing identity must never silently fall back to an unencrypted
 * path.
 */
final class KeyUnavailableException extends RuntimeException implements SecretsException
{
    public static function identityNotConfigured(string $envVar): self
    {
        return new self(sprintf(
            "Off-host key identity is not configured: environment variable '%s' is missing or empty.",
            $envVar,
        ));
    }

    public static function identityMalformed(string $reason): self
    {
        return new self(sprintf('Off-host key identity is malformed: %s', $reason));
    }
}
