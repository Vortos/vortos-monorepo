<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Conformance;

use Vortos\Deploy\Testing\WorkerControllerConformanceTestCase;
use Vortos\Deploy\Worker\WorkerControllerCapability;
use Vortos\Deploy\Worker\WorkerControllerInterface;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;
use Vortos\DeployK8s\Worker\KubernetesWorkerController;

final class KubernetesWorkerControllerConformanceTest extends WorkerControllerConformanceTestCase
{
    private FakeKubeApi $kubeApi;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
    }

    protected function createController(): WorkerControllerInterface
    {
        return new KubernetesWorkerController($this->kubeApi);
    }

    protected function expectedKey(): string
    {
        return 'k8s';
    }

    public function test_remote_control_always_true(): void
    {
        $descriptor = $this->createController()->capabilities();
        $this->assertTrue(
            $descriptor->supports(WorkerControllerCapability::RemoteControl),
            'k8s worker controller is always remote — no local fallback.',
        );
    }
}
