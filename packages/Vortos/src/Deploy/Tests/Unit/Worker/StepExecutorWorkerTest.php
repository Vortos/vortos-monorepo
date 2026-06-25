<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;
use Vortos\Deploy\Tests\Fixtures\FakeWorkerController;
use Vortos\Deploy\Worker\DrainOutcome;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRolloutCoordinator;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class StepExecutorWorkerTest extends TestCase
{
    private FakeDeployStateStore $stateStore;
    private FakeWorkerController $workerController;
    private WorkerProcessRegistry $workerRegistry;

    protected function setUp(): void
    {
        $this->stateStore = new FakeDeployStateStore();
        $this->workerController = new FakeWorkerController();
        $this->workerRegistry = new WorkerProcessRegistry([
            new WorkerProcessDefinition('consumer-main', 'php vortos:consume main', 'Main consumer'),
        ]);
    }

    private function makeExecutor(
        ?WorkerRolloutCoordinator $coordinator = null,
        ?WorkerProcessRegistry $registry = null,
    ): StepExecutor {
        return new StepExecutor(
            stateStore: $this->stateStore,
            registry: new FakeContainerRegistry(),
            readinessGate: new FakeReadinessGate(),
            smokeRunner: new FakeSmokeRunner(),
            composeFactory: new ComposeProjectFactory(),
            localRunner: new FakeCommandRunner(),
            workerCoordinator: $coordinator,
            workerRegistry: $registry,
        );
    }

    private function makeDigestImage(): ImageReference
    {
        return new ImageReference('repo/app', 'v1', 'sha256:' . str_repeat('ab', 32));
    }

    private function makeRun(DeployPlan $plan): DeployRun
    {
        $run = new DeployRun(
            runId: 'run-1',
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
        $this->stateStore->begin($run);

        return $run;
    }

    public function test_drain_worker_invokes_coordinator(): void
    {
        $coordinator = new WorkerRolloutCoordinator($this->workerController);
        $executor = $this->makeExecutor($coordinator, $this->workerRegistry);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::RollWorkers, [
                    new DeployStep(StepAction::DrainWorker, 'drain', ['deadline_seconds' => 25]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan);
        $executor->execute($plan, $run, $this->makeDigestImage());

        $actions = array_column($this->workerController->calls, 'action');
        $this->assertContains('drain', $actions);
        $this->assertContains('launch', $actions);
    }

    public function test_unwired_coordinator_returns_safe_noop(): void
    {
        $executor = $this->makeExecutor(null, null);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::RollWorkers, [
                    new DeployStep(StepAction::DrainWorker, 'drain', ['deadline_seconds' => 25]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan);
        $executor->execute($plan, $run, $this->makeDigestImage());

        $outcome = $run->outcomes()[0] ?? null;
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('not wired', $outcome->result);
    }

    public function test_forced_overrun_does_not_fail_step(): void
    {
        $handle = new WorkerHandle('consumer-main', 1, 25);
        $this->workerController->setDrainResults(
            DrainOutcome::forced($handle, 26000),
        );

        $coordinator = new WorkerRolloutCoordinator($this->workerController);
        $executor = $this->makeExecutor($coordinator, $this->workerRegistry);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::RollWorkers, [
                    new DeployStep(StepAction::DrainWorker, 'drain', ['deadline_seconds' => 25]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan);
        $executor->execute($plan, $run, $this->makeDigestImage());

        $outcome = $run->outcomes()[0] ?? null;
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('forced=1', $outcome->result);
    }

    public function test_empty_worker_registry_skips_drain(): void
    {
        $emptyRegistry = new WorkerProcessRegistry();
        $coordinator = new WorkerRolloutCoordinator($this->workerController);
        $executor = $this->makeExecutor($coordinator, $emptyRegistry);

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::RollWorkers, [
                    new DeployStep(StepAction::DrainWorker, 'drain', ['deadline_seconds' => 25]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan);
        $executor->execute($plan, $run, $this->makeDigestImage());

        $outcome = $run->outcomes()[0] ?? null;
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('no workers', $outcome->result);
    }

    public function test_start_worker_is_noop_passthrough(): void
    {
        $executor = $this->makeExecutor();

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::RollWorkers, [
                    new DeployStep(StepAction::StartWorker, 'start', ['image_digest' => 'sha256:' . str_repeat('ab', 32)]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan);
        $executor->execute($plan, $run, $this->makeDigestImage());

        $outcome = $run->outcomes()[0] ?? null;
        $this->assertNotNull($outcome);
        $this->assertStringContainsString('noop', $outcome->result);
    }
}
