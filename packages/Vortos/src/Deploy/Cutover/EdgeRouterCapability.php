<?php

declare(strict_types=1);

namespace Vortos\Deploy\Cutover;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum EdgeRouterCapability: string implements CapabilityKey
{
    case ConnectionDraining = 'connection_draining';
    case AtomicSwap = 'atomic_swap';
    case VerifiedCutover = 'verified_cutover';
    case WeightedUpstreams = 'weighted_upstreams';
    case DurableState = 'durable_state';

    public function key(): string
    {
        return $this->value;
    }
}
