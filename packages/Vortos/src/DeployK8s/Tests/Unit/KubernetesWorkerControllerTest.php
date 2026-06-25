<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Deploy\Worker\DrainBudget;
use Vortos\Deploy\Worker\WorkerHandle;
use Vortos\Deploy\Worker\WorkerRuntimeStatus;
use Vortos\DeployK8s\Api\RolloutStatus;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;
use Vortos\DeployK8s\Worker\KubernetesWorkerController;

final class KubernetesWorkerControllerTest extends TestCase
{
    private FakeKubeApi $kubeApi;
    private KubernetesWorkerController $controller;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
        $this->controller = new KubernetesWorkerController($this->kubeApi);
    }

    public function test_drain_scales_to_zero(): void
    {
        $handle = new WorkerHandle('queue', 3, 25);
        $budget = new DrainBudget(deadlineSeconds: 10);

        $outcome = $this->controller->drain($handle, $budget);

        $scaleOps = array_filter($this->kubeApi->ops, fn ($op) => $op['op'] === 'scale');
        $this->assertNotEmpty($scaleOps);

        $scaleOp = array_values($scaleOps)[0];
        $this->assertSame(0, $scaleOp['args']['replicas']);
        $this->assertSame('worker-queue', $scaleOp['args']['name']);
    }

    public function test_drain_returns_graceful_when_pods_terminate(): void
    {
        $handle = new WorkerHandle('queue', 1, 25);
        $budget = new DrainBudget(deadlineSeconds: 10);

        $outcome = $this->controller->drain($handle, $budget);

        $this->assertTrue($outcome->inFlightCompleted);
        $this->assertFalse($outcome->forced);
    }

    public function test_launch_scales_to_numprocs(): void
    {
        $handle = new WorkerHandle('mailer', 5, 25);
        $image = new ImageReference('app', digest: 'sha256:' . str_repeat('a', 64));

        $this->controller->launch($handle, $image);

        $scaleOps = array_filter($this->kubeApi->ops, fn ($op) => $op['op'] === 'scale');
        $this->assertNotEmpty($scaleOps);

        $scaleOp = array_values($scaleOps)[0];
        $this->assertSame(5, $scaleOp['args']['replicas']);
    }

    public function test_status_returns_running_when_ready(): void
    {
        $handle = new WorkerHandle('queue', 2, 25);
        $status = $this->controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Running, $status);
    }

    public function test_status_returns_stopped_when_zero_desired(): void
    {
        $this->kubeApi->setNextRolloutStatus(new RolloutStatus(
            ready: false, readyReplicas: 0, desiredReplicas: 0, updatedReplicas: 0,
        ));

        $handle = new WorkerHandle('queue', 1, 25);
        $status = $this->controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Stopped, $status);
    }

    public function test_status_returns_starting_when_partially_ready(): void
    {
        $this->kubeApi->setNextRolloutStatus(new RolloutStatus(
            ready: false, readyReplicas: 1, desiredReplicas: 3, updatedReplicas: 3,
        ));

        $handle = new WorkerHandle('queue', 3, 25);
        $status = $this->controller->status($handle);
        $this->assertSame(WorkerRuntimeStatus::Starting, $status);
    }

    public function test_capabilities_remote_control_always_true(): void
    {
        $caps = $this->controller->capabilities();
        $this->assertTrue($caps->supports('remote_control'));
    }

    public function test_all_worker_capabilities_declared(): void
    {
        $caps = $this->controller->capabilities()->toArray()['capabilities'];
        $this->assertArrayHasKey('graceful_drain', $caps);
        $this->assertArrayHasKey('deadline_bounded', $caps);
        $this->assertArrayHasKey('rolling_recreate', $caps);
        $this->assertArrayHasKey('force_kill_on_overrun', $caps);
        $this->assertArrayHasKey('remote_control', $caps);
        $this->assertArrayHasKey('readiness_after_launch', $caps);
    }
}
