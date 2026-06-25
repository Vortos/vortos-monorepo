<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Definition\EnvironmentName;
use Vortos\Deploy\Plan\DeployPhase;
use Vortos\Deploy\Plan\DeployPlan;
use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Plan\DeployStep;
use Vortos\Deploy\Plan\PhaseKind;
use Vortos\Deploy\Plan\StepAction;
use Vortos\Deploy\State\DeployRun;
use Vortos\Deploy\State\DeployStatus;
use Vortos\Deploy\State\StepOutcome;
use Vortos\Deploy\State\StepStatus;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Target\KubernetesStepExecutor;
use Vortos\DeployK8s\Target\KubernetesTarget;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;

final class KubernetesTargetTest extends TestCase
{
    private FakeKubeApi $kubeApi;
    private FakeDeployStateStore $stateStore;
    private KubernetesTarget $target;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
        $this->stateStore = new FakeDeployStateStore();

        $strategyRegistry = new DeployStrategyRegistry();
        $strategyRegistry->register(new BlueGreenStrategy());

        $renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());

        $executor = new KubernetesStepExecutor(
            kubeApi: $this->kubeApi,
            stateStore: $this->stateStore,
            renderer: $renderer,
        );

        $this->target = new KubernetesTarget(
            planner: new DeployPlanner($strategyRegistry),
            executor: $executor,
            registry: new FakeContainerRegistry(),
            stateStore: $this->stateStore,
            kubeApi: $this->kubeApi,
        );
    }

    public function test_idempotent_release_returns_stored_status(): void
    {
        $plan = $this->makeSimplePlan();
        $planHash = $plan->planHash->toString();

        $run = new DeployRun(
            runId: 'existing-run',
            env: 'production',
            planHash: $planHash,
            definitionHash: $plan->definitionHash,
            desiredDigest: 'sha256:' . str_repeat('a', 64),
            status: DeployStatus::Completed,
        );
        $run->addOutcome(new StepOutcome(0, StepAction::UpdateState, StepStatus::Success, 'promoted color=green'));
        $this->stateStore->begin($run);
        $this->stateStore->complete('existing-run');

        $opsBefore = $this->kubeApi->opCount();
        $status = $this->target->release($plan);
        $opsAfter = $this->kubeApi->opCount();

        $this->assertSame('ok', $status->healthStatus);
        $this->assertSame($opsBefore, $opsAfter, 'Idempotent release must not perform additional KubeApi ops.');
    }

    public function test_status_reads_from_kube_api(): void
    {
        $this->kubeApi->seedService('app', 'default', ['app.kubernetes.io/color' => 'green'], '5', 8080);

        $status = $this->target->status(new EnvironmentName('production'));

        $this->assertSame(ActiveColor::Green, $status->color);
        $this->assertSame('sha256:' . str_repeat('a', 64), $status->imageDigest);
    }

    public function test_status_returns_unknown_when_no_service(): void
    {
        $status = $this->target->status(new EnvironmentName('production'));
        $this->assertSame(ActiveColor::None, $status->color);
        $this->assertSame('unknown', $status->healthStatus);
    }

    public function test_push_delegates_to_registry(): void
    {
        $image = new \Vortos\Deploy\Registry\ImageReference('myapp', tag: 'latest');
        $result = $this->target->push($image);

        $this->assertTrue($result->isDigestPinned());
    }

    public function test_capabilities_returns_k8s_descriptor(): void
    {
        $caps = $this->target->capabilities();
        $this->assertTrue($caps->supports('rolling_across_nodes'));
        $this->assertTrue($caps->supports('canary'));
        $this->assertTrue($caps->supports('blue_green'));
        $this->assertFalse($caps->supports('accepts_downtime'));
    }

    private function makeSimplePlan(): DeployPlan
    {
        return new DeployPlan(
            phases: [
                new DeployPhase(PhaseKind::Promote, [
                    new DeployStep(StepAction::UpdateState, 'record promoted color', ['color' => 'green']),
                ]),
            ],
            definitionHash: 'def-hash-test',
        );
    }
}
