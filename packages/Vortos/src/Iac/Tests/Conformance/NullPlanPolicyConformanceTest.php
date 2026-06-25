<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Conformance;

use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;
use Vortos\Iac\Lifecycle\Policy\PlanPolicyInterface;
use Vortos\Iac\Lifecycle\Testing\PlanPolicyConformanceTestCase;

final class NullPlanPolicyConformanceTest extends PlanPolicyConformanceTestCase
{
    protected function createPolicy(): PlanPolicyInterface
    {
        return new NullPlanPolicy();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
