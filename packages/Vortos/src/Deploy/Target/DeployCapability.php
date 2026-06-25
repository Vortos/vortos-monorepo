<?php

declare(strict_types=1);

namespace Vortos\Deploy\Target;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum DeployCapability: string implements CapabilityKey
{
    case BlueGreen = 'blue_green';
    case RollingAcrossNodes = 'rolling_across_nodes';
    case Canary = 'canary';
    case HealthGate = 'health_gate';
    case AutoRollback = 'auto_rollback';
    case ExpandMigrate = 'expand_migrate';
    case WorkerDrain = 'worker_drain';
    case AcceptsDowntime = 'accepts_downtime';

    public function key(): string
    {
        return $this->value;
    }
}
