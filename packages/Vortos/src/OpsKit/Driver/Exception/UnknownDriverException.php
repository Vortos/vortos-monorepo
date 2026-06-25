<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Exception;

use RuntimeException;

/**
 * Thrown when a concern registry is asked for a driver key that was never
 * registered (e.g. selected in config but the driver package is not installed).
 *
 * The message always names the available keys so the failure is self-diagnosing.
 */
final class UnknownDriverException extends RuntimeException implements OpsKitException
{
    /** @param list<string> $available */
    public static function forKey(string $concern, string $key, array $available): self
    {
        $known = $available === [] ? '(none registered)' : implode(', ', $available);

        return new self(sprintf(
            "No '%s' driver registered for key '%s'. Available: %s.",
            $concern,
            $key,
            $known,
        ));
    }
}
