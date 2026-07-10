<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Testing\DeployTargetConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;

final class SshComposeTargetConformanceTest extends DeployTargetConformanceTestCase
{
    protected function createTarget(): DeployTargetInterface
    {
        $strategyRegistry = new DeployStrategyRegistry();
        $strategyRegistry->register(new BlueGreenStrategy());

        $stateStore = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();

        $executor = new StepExecutor(
            stateStore: $stateStore,
            registry: $registry,
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
        );

        return new SshComposeTarget(
            planner: new DeployPlanner($strategyRegistry),
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            releaseStore: $stateStore,
            reclaimer: new ImageReclaimer(new FakeCommandRunner()),
        );
    }

    protected function expectedKey(): string
    {
        return 'ssh-compose';
    }

    public function test_honestly_unsupported_rolling_across_nodes(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, DeployCapability::RollingAcrossNodes);
    }

    public function test_honestly_unsupported_canary(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, DeployCapability::Canary);
    }

    public function test_honestly_unsupported_accepts_downtime(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, DeployCapability::AcceptsDowntime);
    }
}
