<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

/**
 * The single config-time enforcement point: assert a selected driver actually
 * provides what the selection requires.
 *
 * Concerns (from Block 6 onwards) call this while validating `config/deploy.php`, so
 * an impossible combination — `rolling` on a single-VPS target that declares
 * `rolling_across_nodes=false` — is rejected at config-validation time with a precise,
 * actionable message, never discovered mid-deploy.
 */
final class CapabilityValidator
{
    /**
     * @throws CapabilityMismatchException when $descriptor does not satisfy $required
     */
    public static function assertSatisfies(
        string $driverKey,
        string $concern,
        CapabilityDescriptor $descriptor,
        RequiredCapabilities $required,
    ): void {
        $mismatch = $descriptor->satisfies($required);
        if ($mismatch === null) {
            return;
        }

        throw new CapabilityMismatchException($mismatch, $mismatch->message($driverKey, $concern));
    }
}
