<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Policy;

use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\OpsKit\Driver\DriverInterface;

interface PlanPolicyInterface extends DriverInterface
{
    public function evaluate(IacPlan $plan): PolicyResult;
}
