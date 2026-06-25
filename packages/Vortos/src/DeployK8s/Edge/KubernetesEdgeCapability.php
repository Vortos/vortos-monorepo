<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Edge;

use Vortos\Deploy\Cutover\EdgeRouterCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class KubernetesEdgeCapability
{
    public static function descriptor(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            EdgeRouterCapability::ConnectionDraining->value => true,
            EdgeRouterCapability::AtomicSwap->value => true,
            EdgeRouterCapability::VerifiedCutover->value => true,
            EdgeRouterCapability::WeightedUpstreams->value => true,
            EdgeRouterCapability::DurableState->value => true,
        ]);
    }
}
