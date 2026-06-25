<?php

declare(strict_types=1);

namespace Vortos\Secrets\Exception;

use RuntimeException;
use Vortos\Secrets\Value\SecretKey;

/**
 * Raised when a requested secret does not exist. Fail-closed: a missing secret is
 * never represented as `null` or an empty {@see \Vortos\Secrets\Value\SecretValue}.
 */
final class SecretNotFoundException extends RuntimeException implements SecretsException
{
    public static function forKey(SecretKey $key): self
    {
        return new self(sprintf("Secret '%s' was not found.", $key->value()));
    }
}
