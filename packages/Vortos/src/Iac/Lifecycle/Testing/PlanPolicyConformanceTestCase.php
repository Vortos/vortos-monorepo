<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\Testing;

use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;
use Vortos\Iac\Lifecycle\Policy\PlanPolicyInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

abstract class PlanPolicyConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createPolicy(): PlanPolicyInterface;

    protected function createDriver(): PlanPolicyInterface
    {
        return $this->createPolicy();
    }

    final public function test_clean_plan_passes(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(1, 0, 0, 0),
            [new IacResourceChange('null_resource.test', 'null_resource', IacChangeAction::Create, 'hashicorp/null')],
            '/tmp/plan.bin',
            hash('sha256', 'test'),
            'file-digest',
        );

        $result = $this->createPolicy()->evaluate($plan);
        $this->assertTrue($result->passed(), 'A clean plan must pass the null policy.');
    }

    final public function test_empty_plan_passes(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(0, 0, 0, 0),
            [],
            '/tmp/plan.bin',
            hash('sha256', 'empty'),
            'file-digest',
        );

        $result = $this->createPolicy()->evaluate($plan);
        $this->assertTrue($result->passed(), 'An empty plan must pass.');
    }
}
