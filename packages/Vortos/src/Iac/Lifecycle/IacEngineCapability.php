<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

use Vortos\OpsKit\Driver\Capability\CapabilityKey;

enum IacEngineCapability: string implements CapabilityKey
{
    case RemoteState = 'remote_state';
    case StateLocking = 'state_locking';
    case PlanFile = 'plan_file';
    case Workspaces = 'workspaces';
    case JsonOutput = 'json_output';
    case PolicyGate = 'policy_gate';
    case DirectProvision = 'direct_provision';

    public function key(): string
    {
        return $this->value;
    }
}
