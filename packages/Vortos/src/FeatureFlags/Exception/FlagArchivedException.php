<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Exception;

/**
 * Thrown when a mutation is attempted on a flag that has already been archived.
 * Archived flags are terminal — they must be reverted/unarchived before further change.
 */
final class FlagArchivedException extends \DomainException
{
    public static function forFlag(string $name): self
    {
        return new self(sprintf('Feature flag "%s" is archived and cannot be mutated.', $name));
    }
}
