<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class UnknownDriverSelectionException extends DeployException
{
    /** @param list<string> $available */
    public static function forKey(string $concern, string $key, array $available): self
    {
        return new self(sprintf(
            'Unknown %s driver "%s". Available: [%s].',
            $concern,
            $key,
            implode(', ', $available),
        ));
    }
}
