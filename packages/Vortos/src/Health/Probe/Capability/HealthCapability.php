<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum HealthCapability: string implements CapabilityKey
{
    case DependencyCheck = 'dependency_check';
    case BoundedLatency = 'bounded_latency';
    case ReadOnly = 'read_only';
    case ProcessLocal = 'process_local';

    public function key(): string
    {
        return $this->value;
    }
}
