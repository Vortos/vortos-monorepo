<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Cutover;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Cutover\CutoverCoordinator;
use Vortos\Deploy\Cutover\NullCutoverEventRecorder;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;

final class StepExecutorCutoverTest extends TestCase
{
    public function test_switch_upstream_delegates_to_coordinator(): void
    {
        $router = new FakeEdgeRouter();
        $store = new FakeDeployStateStore();
        $coordinator = new CutoverCoordinator(
            $router,
            $store,
            new NullCutoverEventRecorder(),
        );

        $executor = new StepExecutor(
            stateStore: $store,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
            cutoverCoordinator: $coordinator,
        );

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(
                        StepAction::SwitchUpstream,
                        'Switch upstream',
                        [
                            'from' => 'none',
                            'to' => 'blue',
                            'drain_deadline_seconds' => 5,
                            'image_digest' => 'sha256:' . str_repeat('ab', 32),
                        ],
                    ),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = new DeployRun(
            runId: 'test-run',
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
        $store->begin($run);

        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32));
        $executor->execute($plan, $run, $image);

        $this->assertCount(1, $router->cutoverHistory());
        $this->assertSame(ActiveColor::Blue, $router->cutoverHistory()[0]->activeColor);
    }

    public function test_switch_upstream_revert_throws_deploy_aborted(): void
    {
        $router = new FakeEdgeRouter();
        $store = new FakeDeployStateStore();
        $coordinator = new CutoverCoordinator(
            $router,
            $store,
            new NullCutoverEventRecorder(),
        );

        $executor = new StepExecutor(
            stateStore: $store,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
            cutoverCoordinator: $coordinator,
        );

        $router->setFailCutover(true);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(
                        StepAction::SwitchUpstream,
                        'Switch upstream',
                        ['from' => 'none', 'to' => 'blue', 'drain_deadline_seconds' => 5],
                    ),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = new DeployRun(
            runId: 'test-run',
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
        $store->begin($run);

        $this->expectException(DeployAbortedException::class);
        $executor->execute($plan, $run, new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32)));
    }

    public function test_switch_upstream_without_coordinator_returns_message(): void
    {
        $store = new FakeDeployStateStore();

        $executor = new StepExecutor(
            stateStore: $store,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: new FakeCommandRunner(),
        );

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(
                        StepAction::SwitchUpstream,
                        'Switch upstream',
                        ['from' => 'none', 'to' => 'blue'],
                    ),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = new DeployRun(
            runId: 'test-run',
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
        $store->begin($run);

        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('ab', 32));
        $executor->execute($plan, $run, $image);

        $this->assertTrue($run->isStepCompleted(0));
    }
}
