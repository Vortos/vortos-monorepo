<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Preflight\Check;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Preflight\Check\CapabilityDescriptorCheck;
use Vortos\Deploy\Preflight\PreflightStatus;
use Vortos\Deploy\Strategy\DeployStrategy;
use Vortos\Deploy\Tests\Fixtures\PreflightTestFactory;

final class CapabilityDescriptorCheckTest extends TestCase
{
    use PreflightTestFactory;

    private function check(): CapabilityDescriptorCheck
    {
        return new CapabilityDescriptorCheck($this->targetRegistry(), $this->strategyRegistry());
    }

    public function test_satisfied_strategy_passes(): void
    {
        // FakeDeployTarget supports blue-green + health-gate.
        $finding = $this->check()->check($this->context());

        $this->assertSame(PreflightStatus::Pass, $finding->status);
    }

    public function test_unsupported_strategy_fails(): void
    {
        // Rolling requires rolling_across_nodes, which FakeDeployTarget declares false.
        $ctx = $this->context($this->definition(strategy: DeployStrategy::Rolling));

        $finding = $this->check()->check($ctx);

        $this->assertSame(PreflightStatus::Fail, $finding->status);
        $this->assertStringContainsString('rolling', $finding->summary);
    }

    public function test_unregistered_target_fails_without_throwing(): void
    {
        $finding = $this->check()->check($this->context($this->definition(host: 'ghost')));

        $this->assertSame(PreflightStatus::Fail, $finding->status);
    }
}
