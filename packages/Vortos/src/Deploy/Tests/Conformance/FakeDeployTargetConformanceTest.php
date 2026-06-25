<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Testing\DeployTargetConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeDeployTarget;

final class FakeDeployTargetConformanceTest extends DeployTargetConformanceTestCase
{
    protected function createTarget(): DeployTargetInterface
    {
        return new FakeDeployTarget();
    }

    protected function expectedKey(): string
    {
        return 'fake-target';
    }

    public function test_single_node_target_honestly_reports_no_rolling(): void
    {
        $target = new \Vortos\Deploy\Tests\Fixtures\SingleNodeTarget();
        $this->assertHonestlyUnsupported($target->capabilities(), 'rolling_across_nodes');
    }

    public function test_single_node_target_honestly_reports_no_canary(): void
    {
        $target = new \Vortos\Deploy\Tests\Fixtures\SingleNodeTarget();
        $this->assertHonestlyUnsupported($target->capabilities(), 'canary');
    }
}
