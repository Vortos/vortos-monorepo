<?php

declare(strict_types=1);

namespace Vortos\Backup\Domain\Exception;

/**
 * Raised when an unknown database engine is requested — fail closed, never guess.
 */
final class UnknownEngineException extends BackupException
{
    /** @param list<string> $known */
    public static function forValue(string $value, array $known): self
    {
        return new self(sprintf(
            "Unknown database engine '%s'. Known engines: %s.",
            $value,
            $known === [] ? '(none)' : implode(', ', $known),
        ));
    }
}
