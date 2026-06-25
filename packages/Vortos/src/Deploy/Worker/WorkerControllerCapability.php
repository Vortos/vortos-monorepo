<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum WorkerControllerCapability: string implements CapabilityKey
{
    case GracefulDrain = 'graceful_drain';
    case DeadlineBounded = 'deadline_bounded';
    case RollingRecreate = 'rolling_recreate';
    case ForceKillOnOverrun = 'force_kill_on_overrun';
    case RemoteControl = 'remote_control';
    case ReadinessAfterLaunch = 'readiness_after_launch';

    public function key(): string
    {
        return $this->value;
    }
}
