<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Definition\DeploymentDefinition;
use Vortos\Deploy\Driver\SshCompose\SshComposeCapability;
use Vortos\Deploy\Driver\SshCompose\SshComposeTarget;
use Vortos\Deploy\Driver\Docker\ImageReclaimer;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\State\CurrentRelease;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\Target\ActiveColor;
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

    public function test_completed_run_short_circuits_only_when_the_live_release_matches(): void
    {
        // A Completed run whose image is NOT the live release is a phantom success (the historical
        // 32s "deployed" that never actually rolled anything). release() must re-run it, not trust it.
        [$target, $stateStore, $runner, $plan, $digest] = $this->targetWithSeededCompletedRun();

        $target->release($plan, new EnvironmentName('production'));

        $this->assertNotEmpty(
            $runner->calls,
            'A completed run with no matching live release must RE-RUN the deploy, not short-circuit.',
        );
    }

    public function test_completed_run_with_matching_live_release_is_a_true_no_op(): void
    {
        [$target, $stateStore, $runner, $plan, $digest] = $this->targetWithSeededCompletedRun();

        // Record the desired digest as the verified-live release → the short-circuit is now honest.
        $stateStore->recordCurrentRelease(new CurrentRelease(
            env: 'production',
            activeColor: ActiveColor::Blue,
            imageDigest: $digest,
            buildId: 'build-1',
            planHash: $plan->planHash->toString(),
            recordedAt: new \DateTimeImmutable(),
            generation: 1,
        ));

        $target->release($plan, new EnvironmentName('production'));

        $this->assertEmpty(
            $runner->calls,
            'A completed run that IS the live release must short-circuit without re-executing.',
        );
    }

    /**
     * @return array{0: SshComposeTarget, 1: FakeDeployStateStore, 2: FakeCommandRunner, 3: \Vortos\Deploy\Plan\DeployPlan, 4: string}
     */
    private function targetWithSeededCompletedRun(): array
    {
        $strategyRegistry = new DeployStrategyRegistry();
        $strategyRegistry->register(new BlueGreenStrategy());

        $stateStore = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();
        $runner = new FakeCommandRunner();

        $executor = new StepExecutor(
            stateStore: $stateStore,
            registry: $registry,
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $runner,
        );

        $target = new SshComposeTarget(
            planner: new DeployPlanner($strategyRegistry),
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            releaseStore: $stateStore,
            reclaimer: new ImageReclaimer(new FakeCommandRunner()),
        );

        $digest = 'sha256:' . str_repeat('ab', 32);
        $manifest = new BuildManifest(
            'build-1',
            'abc1234',
            'ghcr.io/acme/app',
            $digest,
            Arch::Arm64,
            'production',
            SchemaFingerprint::empty(),
            new \DateTimeImmutable(),
        );
        $context = new DeployContext(DeploymentDefinition::build(), $manifest, CurrentDeployState::firstDeploy());
        $plan = $target->plan($context);

        // Seed a prior run for this exact plan, marked Completed.
        $run = new DeployRun(
            runId: 'run-phantom',
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: $plan->definitionHash,
            desiredDigest: $digest,
            desiredRepository: 'ghcr.io/acme/app',
        );
        $stateStore->begin($run);
        $stateStore->complete('run-phantom');

        return [$target, $stateStore, $runner, $plan, $digest];
    }
}
