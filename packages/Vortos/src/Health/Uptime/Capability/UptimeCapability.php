<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Capability;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum UptimeCapability: string implements CapabilityKey
{
    case SyntheticJourney = 'synthetic_journey';
    case MultiRegion = 'multi_region';
    case IncidentApi = 'incident_api';
    case ResponseTimeSlo = 'response_time_slo';

    public function key(): string
    {
        return $this->value;
    }
}
