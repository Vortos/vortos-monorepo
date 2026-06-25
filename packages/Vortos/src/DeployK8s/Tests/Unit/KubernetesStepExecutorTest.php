<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Exception\DeployAbortedException;
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
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\DeployK8s\Api\JobStatus;
use Vortos\DeployK8s\Api\RolloutStatus;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Target\KubernetesStepExecutor;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;

final class KubernetesStepExecutorTest extends TestCase
{
    private FakeKubeApi $kubeApi;
    private FakeDeployStateStore $stateStore;
    private KubernetesStepExecutor $executor;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
        $this->stateStore = new FakeDeployStateStore();

        $renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());

        $this->executor = new KubernetesStepExecutor(
            kubeApi: $this->kubeApi,
            stateStore: $this->stateStore,
            renderer: $renderer,
        );
    }

    public function test_pull_image_asserts_digest_pinned(): void
    {
        $plan = $this->makePlan([new DeployStep(StepAction::PullImage, 'pull')]);
        $run = $this->makeRun($plan);

        $image = new ImageReference('app', digest: null);

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessageMatches('/not digest-pinned/i');

        $this->executor->execute($plan, $run, $image);
    }

    public function test_pull_image_succeeds_when_pinned(): void
    {
        $plan = $this->makePlan([new DeployStep(StepAction::PullImage, 'pull')]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $this->assertTrue($run->isStepCompleted(0));
    }

    public function test_start_container_applies_kube_objects(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::StartContainer, 'start', ['color' => 'green']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('apply', $ops);
    }

    public function test_start_container_rejects_unpinned_image(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::StartContainer, 'start', ['color' => 'blue']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: null);

        $this->expectException(DeployAbortedException::class);
        $this->executor->execute($plan, $run, $image);
    }

    public function test_check_health_throws_on_not_ready(): void
    {
        $this->kubeApi->setNextRolloutStatus(new RolloutStatus(
            ready: false, readyReplicas: 0, desiredReplicas: 2, updatedReplicas: 2,
        ));

        $plan = $this->makePlan([
            new DeployStep(StepAction::CheckHealth, 'health', ['color' => 'blue', 'max_attempts' => 1]),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessageMatches('/health gate failed/i');

        $this->executor->execute($plan, $run, $image);
    }

    public function test_check_health_succeeds_when_ready(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::CheckHealth, 'health', ['color' => 'blue', 'max_attempts' => 1]),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $this->assertTrue($run->isStepCompleted(0));
    }

    public function test_run_smoke_throws_when_not_ready(): void
    {
        $this->kubeApi->setNextRolloutStatus(new RolloutStatus(
            ready: false, readyReplicas: 0, desiredReplicas: 2, updatedReplicas: 2,
        ));

        $plan = $this->makePlan([
            new DeployStep(StepAction::RunSmoke, 'smoke', ['color' => 'blue']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->expectException(DeployAbortedException::class);
        $this->expectExceptionMessageMatches('/smoke.*failed/i');

        $this->executor->execute($plan, $run, $image);
    }

    public function test_switch_upstream_patches_service_selector(): void
    {
        $this->kubeApi->seedService('app', 'default', ['app.kubernetes.io/color' => 'blue'], '1');

        $plan = $this->makePlan([
            new DeployStep(StepAction::SwitchUpstream, 'cutover', ['color' => 'green', 'app_name' => 'app']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('patchServiceSelector', $ops);
    }

    public function test_run_migrations_creates_and_awaits_job(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::RunMigrations, 'migrate', ['migration_command' => 'php migrate']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('createJob', $ops);
        $this->assertContains('awaitJob', $ops);
    }

    public function test_run_migrations_throws_on_job_failure(): void
    {
        $this->kubeApi->setNextJobStatus(new JobStatus(completed: false, failed: true, message: 'schema error'));

        $plan = $this->makePlan([
            new DeployStep(StepAction::RunMigrations, 'migrate', ['migration_command' => 'php migrate']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/migration job failed/i');

        $this->executor->execute($plan, $run, $image);
    }

    public function test_run_migrations_rejects_unpinned_image(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::RunMigrations, 'migrate', ['migration_command' => 'php migrate']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: null);

        $this->expectException(DeployAbortedException::class);
        $this->executor->execute($plan, $run, $image);
    }

    public function test_drain_worker_scales_to_zero(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::DrainWorker, 'drain', ['worker_name' => 'queue', 'numprocs' => 2, 'drain_deadline' => 10]),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('scale', $ops);
    }

    public function test_start_worker_scales_up(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::StartWorker, 'start', ['worker_name' => 'queue', 'numprocs' => 3, 'drain_deadline' => 10]),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('scale', $ops);
    }

    public function test_stop_container_scales_to_zero(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::StopContainer, 'stop', ['color' => 'blue']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $ops = $this->kubeApi->opNames();
        $this->assertContains('scale', $ops);
    }

    public function test_noop_steps_complete_without_ops(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::Noop, 'noop'),
            new DeployStep(StepAction::WaitDrain, 'wait'),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $opsBefore = $this->kubeApi->opCount();
        $this->executor->execute($plan, $run, $image);
        $opsAfter = $this->kubeApi->opCount();

        $this->assertSame($opsBefore, $opsAfter);
        $this->assertTrue($run->isStepCompleted(0));
        $this->assertTrue($run->isStepCompleted(1));
    }

    public function test_skips_already_completed_steps(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::PullImage, 'pull'),
            new DeployStep(StepAction::UpdateState, 'state', ['color' => 'green']),
        ]);
        $run = $this->makeRun($plan);

        $run->addOutcome(new StepOutcome(0, StepAction::PullImage, StepStatus::Success, 'done'));

        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));
        $this->executor->execute($plan, $run, $image);

        $this->assertSame(0, $this->kubeApi->opCount(), 'Already-completed pull step should be skipped.');
        $this->assertTrue($run->isStepCompleted(1));
    }

    public function test_update_state_records_promoted_color(): void
    {
        $plan = $this->makePlan([
            new DeployStep(StepAction::UpdateState, 'promote', ['color' => 'green']),
        ]);
        $run = $this->makeRun($plan);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->executor->execute($plan, $run, $image);

        $outcomes = $run->outcomes();
        $this->assertStringContainsString('promoted color=green', $outcomes[0]->result);
    }

    /** @param list<DeployStep> $steps */
    private function makePlan(array $steps): DeployPlan
    {
        return new DeployPlan(
            phases: [new DeployPhase(PhaseKind::Promote, $steps)],
            definitionHash: 'test-hash',
        );
    }

    private function makeRun(DeployPlan $plan): DeployRun
    {
        $run = new DeployRun(
            runId: 'test-run-' . bin2hex(random_bytes(4)),
            env: 'production',
            planHash: $plan->planHash->toString(),
            definitionHash: $plan->definitionHash,
            desiredDigest: 'sha256:' . str_repeat('a', 64),
            status: DeployStatus::Running,
        );
        $this->stateStore->begin($run);
        return $run;
    }
}
