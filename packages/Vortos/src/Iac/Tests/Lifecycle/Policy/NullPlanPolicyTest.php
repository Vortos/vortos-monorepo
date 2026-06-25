<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Lifecycle\Policy;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\IacChangeAction;
use Vortos\Iac\Lifecycle\IacPlan;
use Vortos\Iac\Lifecycle\IacPlanSummary;
use Vortos\Iac\Lifecycle\IacResourceChange;
use Vortos\Iac\Lifecycle\Policy\NullPlanPolicy;

final class NullPlanPolicyTest extends TestCase
{
    public function test_always_passes(): void
    {
        $plan = new IacPlan(
            new IacPlanSummary(5, 3, 2, 1),
            [new IacResourceChange('r.x', 't', IacChangeAction::Delete, 'p')],
            '/tmp/plan.bin',
            'digest',
            'file-digest',
        );

        $result = (new NullPlanPolicy())->evaluate($plan);
        $this->assertTrue($result->passed());
    }

    public function test_has_capabilities(): void
    {
        $descriptor = (new NullPlanPolicy())->capabilities();
        $this->assertSame([], $descriptor->capabilities);
    }
}
