<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

use RuntimeException;
use Vortos\OpsKit\Driver\Exception\OpsKitException;

/**
 * Raised at config-validation time when a selected driver cannot satisfy what the
 * selection requires — the "fail at config time, never at 3am" guarantee.
 *
 * Carries the structured {@see CapabilityMismatch} so callers (e.g. `deploy:doctor`,
 * Block 12) can render or diff it, not just read a flat string.
 */
final class CapabilityMismatchException extends RuntimeException implements OpsKitException
{
    public function __construct(
        public readonly CapabilityMismatch $mismatch,
        string $message,
    ) {
        parent::__construct($message);
    }
}
