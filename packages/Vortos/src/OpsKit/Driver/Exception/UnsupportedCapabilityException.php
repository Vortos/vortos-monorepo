<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Exception;

use RuntimeException;
use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * The honest failure a driver MUST raise when asked to perform an operation it has
 * declared it does not support — never a silent no-op.
 *
 * This is the anti-drift mechanism: the conformance TCK asserts that calling an
 * unsupported operation raises this exception, which is what stops a driver quietly
 * pretending everything works (the failure mode that surfaces only during an
 * incident).
 */
final class UnsupportedCapabilityException extends RuntimeException implements OpsKitException
{
    public static function for(string $driverKey, CapabilityKey|string $capability): self
    {
        $name = $capability instanceof CapabilityKey ? $capability->key() : $capability;

        return new self(sprintf(
            "Driver '%s' does not support capability '%s'.",
            $driverKey,
            $name,
        ));
    }
}
