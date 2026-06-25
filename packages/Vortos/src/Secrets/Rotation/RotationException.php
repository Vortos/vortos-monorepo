<?php

declare(strict_types=1);

namespace Vortos\Secrets\Rotation;

use RuntimeException;
use Vortos\Secrets\Exception\SecretsException;

final class RotationException extends RuntimeException implements SecretsException
{
    public static function invalidPolicy(string $reason): self
    {
        return new self("Invalid rotation policy: {$reason}");
    }
}
