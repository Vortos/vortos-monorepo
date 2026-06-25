<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Policy;

use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

#[AsDriver('null')]
final class NullPlanPolicy implements PlanPolicyInterface
{
    public function evaluate(IacPlan $plan): PolicyResult
    {
        return PolicyResult::pass();
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }
}
