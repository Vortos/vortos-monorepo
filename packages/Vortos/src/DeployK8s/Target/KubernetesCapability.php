<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Target;

use Vortos\Deploy\Target\DeployCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class KubernetesCapability
{
    public static function descriptor(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            capabilities: [
                DeployCapability::BlueGreen->value => true,
                DeployCapability::RollingAcrossNodes->value => true,
                DeployCapability::Canary->value => true,
                DeployCapability::HealthGate->value => true,
                DeployCapability::AutoRollback->value => true,
                DeployCapability::ExpandMigrate->value => true,
                DeployCapability::WorkerDrain->value => true,
                DeployCapability::AcceptsDowntime->value => false,
            ],
            constraints: [],
        );
    }
}
