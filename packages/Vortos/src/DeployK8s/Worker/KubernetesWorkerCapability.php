<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Worker;

use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class KubernetesWorkerCapability
{
    public static function descriptor(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            WorkerControllerCapability::GracefulDrain->value => true,
            WorkerControllerCapability::DeadlineBounded->value => true,
            WorkerControllerCapability::RollingRecreate->value => true,
            WorkerControllerCapability::ForceKillOnOverrun->value => true,
            WorkerControllerCapability::RemoteControl->value => true,
            WorkerControllerCapability::ReadinessAfterLaunch->value => true,
        ]);
    }
}
