<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver;

use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * The universal contract every driver — of every concern — satisfies.
 *
 * A concern's port interface (e.g. `DeployTargetInterface`, `HealthProbeInterface`)
 * extends this, so every driver, regardless of concern, can be asked what it can
 * actually do. Capability honesty is the foundation of the whole swappability
 * contract: the descriptor is the single source validated at config time
 * (never at 3am), and a driver that pretends to support something it does not is
 * the exact failure the conformance TCK exists to catch.
 */
interface DriverInterface
{
    /**
     * The set of capabilities this driver actually supports.
     *
     * Pure and side-effect-free: it must return the same descriptor every call and
     * must not perform I/O. The returned descriptor is diffed against the required
     * capabilities at config-validation time.
     */
    public function capabilities(): CapabilityDescriptor;
}
