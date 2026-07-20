<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Compose\ComposeProjectFactory;
use Vortos\Deploy\Runtime\RuntimeServiceSpec;
use Vortos\Deploy\Driver\SshCompose\StepExecutor;
use Vortos\Deploy\Exception\DeployAbortedException;
use Vortos\Deploy\Plan\CurrentDeployState;
use Vortos\Deploy\Plan\DeployContext;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeCommandRunner;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\Deploy\Tests\Fixtures\FakeReadinessGate;
use Vortos\Deploy\Tests\Fixtures\FakeSmokeRunner;

final class StepExecutorTest extends TestCase
{
    private FakeDeployStateStore $stateStore;
    private FakeContainerRegistry $registry;
    private FakeReadinessGate $gate;
    private FakeSmokeRunner $smokeRunner;
    private FakeCommandRunner $runner;
    private StepExecutor $executor;

    protected function setUp(): void
    {
        $this->stateStore = new FakeDeployStateStore();
        $this->registry = new FakeContainerRegistry();
        $this->gate = new FakeReadinessGate();
        $this->smokeRunner = new FakeSmokeRunner();
        $this->runner = new FakeCommandRunner();

        $this->executor = new StepExecutor(
            stateStore: $this->stateStore,
            registry: $this->registry,
            readinessGate: $this->gate,
            smokeRunner: $this->smokeRunner,
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $this->runner,
        );
    }

    private function makeDigestImage(): ImageReference
    {
        return new ImageReference('repo/app', 'v1', 'sha256:' . str_repeat('ab', 32));
    }

    private function makeRun(string $planHash = 'sha256:plan123'): DeployRun
    {
        $run = new DeployRun(
            runId: 'run-1',
            env: 'production',
            planHash: $planHash,
            definitionHash: 'sha256:def',
            desiredDigest: 'sha256:' . str_repeat('ab', 32),
        );
        $this->stateStore->begin($run);

        return $run;
    }

    public function test_pull_image_runs_on_target_over_ssh_in_push_mode(): void
    {
        $transport = new \Vortos\Deploy\Tests\Fixtures\FakeSshTransport();
        $executor = new StepExecutor(
            stateStore: $this->stateStore,
            registry: $this->registry,
            readinessGate: $this->gate,
            smokeRunner: $this->smokeRunner,
            composeFactory: new ComposeProjectFactory(new RuntimeServiceSpec()),
            localRunner: $this->runner,
            sshTransport: $transport,
        );

        $digest = 'sha256:' . str_repeat('ab', 32);
        $repo = 'ghcr.io/acme/app';
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::PullImage, 'Pull', ['image_digest' => $digest, 'image_repository' => $repo]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );
        $run = $this->makeRun($plan->planHash->toString());

        $executor->execute($plan, $run, new ImageReference($repo, digest: $digest));

        // The pull happened on the VPS (docker pull repo@digest over SSH), not on the runner.
        $this->assertCount(1, $transport->commands);
        $this->assertSame(['docker', 'pull', $repo . '@' . $digest], $transport->commands[0]->argv);
    }

    public function test_walks_full_blue_green_plan(): void
    {
        $digest = 'sha256:' . str_repeat('ab', 32);
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::PullImage, 'Pull', ['image_digest' => $digest]),
                    new DeployStep(StepAction::StartContainer, 'Start green', ['color' => 'green', 'image_digest' => $digest]),
                ]),
                new DeployPhase(PhaseKind::HealthGate, [
                    new DeployStep(StepAction::CheckHealth, 'Health', ['color' => 'green', 'timeout_seconds' => 10]),
                ]),
                new DeployPhase(PhaseKind::Smoke, [
                    new DeployStep(StepAction::RunSmoke, 'Smoke', ['color' => 'green']),
                ]),
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(StepAction::SwitchUpstream, 'Switch', ['from' => 'blue', 'to' => 'green']),
                ]),
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'Promote', ['color' => 'green', 'image_digest' => $digest]),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        $this->executor->execute($plan, $run, $this->makeDigestImage());

        $this->assertSame(6, $run->completedStepCount());
    }

    public function test_decommission_phase_tears_down_previous_color(): void
    {
        // The Decommission phase: confirm the newly-promoted (green) color is serving, THEN reap the
        // previous (blue) color — a single `docker compose -p vortos-app-blue down` that removes blue's
        // app AND worker together.
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Decommission, [
                    new DeployStep(StepAction::CheckHealth, 'Confirm green serving', ['color' => 'green', 'timeout_seconds' => 10]),
                    new DeployStep(StepAction::StopContainer, 'Tear down blue', ['color' => 'blue']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        $this->executor->execute($plan, $run, $this->makeDigestImage());

        $argvs = array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);
        $this->assertContains(
            ['docker', 'compose', '-p', 'vortos-app-blue', 'down'],
            $argvs,
            'Decommission must tear down the previous (blue) color.',
        );
        $this->assertSame(2, $run->completedStepCount());
    }

    public function test_failed_post_cutover_health_aborts_before_teardown(): void
    {
        // Safety property: if the promoted color is NOT serving after cutover, the post-cutover health
        // re-check throws and the deploy aborts BEFORE the StopContainer — so the old color is left
        // running and rollback can route back to it.
        $this->gate->shouldPass = false;

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Decommission, [
                    new DeployStep(StepAction::CheckHealth, 'Confirm green serving', ['color' => 'green', 'timeout_seconds' => 10]),
                    new DeployStep(StepAction::StopContainer, 'Tear down blue', ['color' => 'blue']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());

        try {
            $this->executor->execute($plan, $run, $this->makeDigestImage());
            $this->fail('Expected DeployAbortedException when post-cutover health fails.');
        } catch (DeployAbortedException) {
            // expected
        }

        $argvs = array_map(static fn (array $c): array => $c['argv'], $this->runner->calls);
        $this->assertNotContains(
            ['docker', 'compose', '-p', 'vortos-app-blue', 'down'],
            $argvs,
            'The old color must NOT be torn down when the post-cutover health check fails.',
        );
    }

    public function test_resumes_from_completed_steps(): void
    {
        $digest = 'sha256:' . str_repeat('ab', 32);
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::PullImage, 'Pull', ['image_digest' => $digest]),
                    new DeployStep(StepAction::StartContainer, 'Start', ['color' => 'green', 'image_digest' => $digest]),
                ]),
                new DeployPhase(PhaseKind::HealthGate, [
                    new DeployStep(StepAction::CheckHealth, 'Health', ['color' => 'green']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        // Pre-seed steps 0 and 1 as completed
        $run->addOutcome(new StepOutcome(0, StepAction::PullImage, StepStatus::Success));
        $run->addOutcome(new StepOutcome(1, StepAction::StartContainer, StepStatus::Success));

        $this->executor->execute($plan, $run, $this->makeDigestImage());

        // Only step 2 (CheckHealth) should have been newly executed
        $this->assertSame(3, $run->completedStepCount());
    }

    public function test_idempotent_completed_run(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'Promote', ['color' => 'green']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        $run->addOutcome(new StepOutcome(0, StepAction::UpdateState, StepStatus::Success));

        // All steps already completed — execute should be a no-op
        $previousCount = $run->completedStepCount();
        $this->executor->execute($plan, $run, $this->makeDigestImage());

        $this->assertSame($previousCount, $run->completedStepCount());
    }

    public function test_health_gate_failure_aborts(): void
    {
        $this->gate->shouldPass = false;
        $this->gate->attempts = 30;

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::HealthGate, [
                    new DeployStep(StepAction::CheckHealth, 'Health', ['color' => 'green']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessage('Health gate failed');
        $this->executor->execute($plan, $run, $this->makeDigestImage());
    }

    public function test_smoke_failure_aborts(): void
    {
        $this->smokeRunner->shouldPass = false;

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Smoke, [
                    new DeployStep(StepAction::RunSmoke, 'Smoke', ['color' => 'green']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessage('Smoke test failed');
        $this->executor->execute($plan, $run, $this->makeDigestImage());
    }

    public function test_digest_guard_rejects_mutable_tag(): void
    {
        $mutableImage = new ImageReference('repo/app', 'latest');

        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::StageColor, [
                    new DeployStep(StepAction::PullImage, 'Pull', ['image_digest' => 'latest']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessage('not digest-pinned');
        $this->executor->execute($plan, $run, $mutableImage);
    }

    public function test_noop_steps_pass_through(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::ContractGuard, [
                    new DeployStep(StepAction::Noop, 'Deferred'),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        $this->executor->execute($plan, $run, $this->makeDigestImage());

        $this->assertSame(1, $run->completedStepCount());
    }

    public function test_stub_handlers_record_result(): void
    {
        $plan = new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Cutover, [
                    new DeployStep(StepAction::SwitchUpstream, 'Switch', ['from' => 'blue', 'to' => 'green']),
                ]),
                new DeployPhase(PhaseKind::ExpandMigrate, [
                    new DeployStep(StepAction::RunMigrations, 'Migrate', ['fingerprint' => 'sha256:fp']),
                ]),
            ],
            definitionHash: 'sha256:def',
        );

        $run = $this->makeRun($plan->planHash->toString());
        $this->executor->execute($plan, $run, $this->makeDigestImage());

        $outcomes = $run->outcomes();
        $this->assertStringContainsString('upstream switch', $outcomes[0]->result);
        $this->assertStringContainsString('migrations applied', $outcomes[1]->result);
    }
}
