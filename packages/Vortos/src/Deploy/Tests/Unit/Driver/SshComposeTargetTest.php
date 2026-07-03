<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Driver\SshCompose\SshComposeCapability;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Release\Manifest\Arch;
use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Schema\SchemaFingerprint;

final class SshComposeTargetTest extends TestCase
{
    private function createTarget(): SshComposeTarget
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
            composeFactory: new ComposeProjectFactory(),
            localRunner: new FakeCommandRunner(),
        );

        return new SshComposeTarget(
            planner: new DeployPlanner($strategyRegistry),
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            releaseStore: $stateStore,
        );
    }

    public function test_capability_descriptor(): void
    {
        $caps = SshComposeCapability::descriptor();

        $this->assertTrue($caps->supports(DeployCapability::BlueGreen));
        $this->assertTrue($caps->supports(DeployCapability::HealthGate));
        $this->assertTrue($caps->supports(DeployCapability::AutoRollback));
        $this->assertTrue($caps->supports(DeployCapability::ExpandMigrate));
        $this->assertTrue($caps->supports(DeployCapability::WorkerDrain));
        $this->assertFalse($caps->supports(DeployCapability::RollingAcrossNodes));
        $this->assertFalse($caps->supports(DeployCapability::Canary));
        $this->assertFalse($caps->supports(DeployCapability::AcceptsDowntime));
    }

    public function test_plan_delegates_to_planner(): void
    {
        $target = $this->createTarget();
        $definition = DeploymentDefinition::build();
        $manifest = new BuildManifest(
            'build-1',
            'abc1234',
            'ghcr.io/acme/app',
            'sha256:' . str_repeat('ab', 32),
            Arch::Arm64,
            'production',
            SchemaFingerprint::empty(),
            new \DateTimeImmutable(),
        );

        $context = new DeployContext(
            $definition,
            $manifest,
            CurrentDeployState::firstDeploy(),
        );

        $plan = $target->plan($context);

        $this->assertFalse($plan->isEmpty());
        $this->assertGreaterThan(0, $plan->phaseCount());
    }

    public function test_capabilities_match_descriptor(): void
    {
        $target = $this->createTarget();
        $caps = $target->capabilities();

        $this->assertTrue($caps->supports(DeployCapability::BlueGreen));
        $this->assertFalse($caps->supports(DeployCapability::Canary));
    }
}
