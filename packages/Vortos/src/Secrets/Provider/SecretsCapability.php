<?php

declare(strict_types=1);

namespace Vortos\Secrets\Provider;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

/**
 * What a {@see SecretsProviderInterface} driver actually does, declared honestly
 * via {@see \Vortos\OpsKit\Driver\Capability\CapabilityDescriptor} rather than
 * assumed from its name. A driver that cannot, say, rotate (`Rotation`) must say
 * so — the conformance TCK rejects drivers that lie.
 */
enum SecretsCapability: string implements CapabilityKey
{
    case Versioning = 'versioning';
    case Rotation = 'rotation';
    case TwoPhaseRotation = 'two_phase_rotation';
    case OffHostKey = 'off_host_key';
    case Put = 'put';
    case ListKeys = 'list';
    case InMemoryOnly = 'in_memory_only';

    public function key(): string
    {
        return $this->value;
    }
}
