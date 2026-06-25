<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Driver\Capability;

/**
 * A type-safe capability key.
 *
 * Each concern declares its own backed enum implementing this interface
 * (e.g. `enum DeployCapability: string implements CapabilityKey`), so capabilities
 * are referred to by a typed, autocompletable symbol rather than a magic string.
 * The string form is what is serialized into a {@see CapabilityDescriptor} and what
 * `deploy:doctor` (Block 12) diffs — so it must be stable.
 */
interface CapabilityKey
{
    /** The stable string identity of this capability (lower_snake by convention). */
    public function key(): string;
}
