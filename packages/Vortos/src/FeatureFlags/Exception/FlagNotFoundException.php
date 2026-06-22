<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

/**
 * Thrown by the write side when a mutation targets a flag that does not exist.
 */
final class FlagNotFoundException extends \RuntimeException
{
    public static function forName(string $name): self
    {
        return new self(sprintf('Feature flag "%s" not found.', $name));
    }
}
