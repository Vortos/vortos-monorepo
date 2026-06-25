<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Exception;

use LogicException;

/**
 * Thrown at container compile time by {@see \Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass}
 * when a driver is mis-declared — a missing/malformed key, or two drivers claiming
 * the same key within one concern.
 *
 * It is a {@see LogicException} on purpose: every one of these is a programming /
 * wiring error that must fail the build, never reach a deploy.
 */
final class DriverCollectionException extends LogicException implements OpsKitException
{
    public static function missingKey(string $concern, string $serviceId): self
    {
        return new self(sprintf(
            "Driver service '%s' tagged for concern '%s' has no key. Add #[AsDriver(key: 'your-key')] to the class.",
            $serviceId,
            $concern,
        ));
    }

    public static function malformedKey(string $concern, string $serviceId, string $key): self
    {
        return new self(sprintf(
            "Driver '%s' (service '%s', concern '%s') has an invalid key. Keys must match ^[a-z][a-z0-9-]*$ (lower-kebab).",
            $key,
            $serviceId,
            $concern,
        ));
    }

    public static function duplicateKey(string $concern, string $key, string $firstService, string $secondService): self
    {
        return new self(sprintf(
            "Two '%s' drivers claim key '%s': '%s' and '%s'. Each driver key must be unique within a concern.",
            $concern,
            $key,
            $firstService,
            $secondService,
        ));
    }

    public static function missingClass(string $concern, string $serviceId): self
    {
        return new self(sprintf(
            "Driver service '%s' tagged for concern '%s' has no resolvable class.",
            $serviceId,
            $concern,
        ));
    }
}
