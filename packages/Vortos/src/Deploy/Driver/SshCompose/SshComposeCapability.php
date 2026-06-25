<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\SshCompose;

use Vortos\Deploy\Target\DeployCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class SshComposeCapability
{
    public static function descriptor(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create(
            capabilities: [
                DeployCapability::BlueGreen->value => true,
                DeployCapability::HealthGate->value => true,
                DeployCapability::AutoRollback->value => true,
                DeployCapability::ExpandMigrate->value => true,
                DeployCapability::WorkerDrain->value => true,
                DeployCapability::RollingAcrossNodes->value => false,
                DeployCapability::Canary->value => false,
                DeployCapability::AcceptsDowntime->value => false,
            ],
            constraints: [
                'target_arch' => 'linux/arm64',
            ],
        );
    }
}
