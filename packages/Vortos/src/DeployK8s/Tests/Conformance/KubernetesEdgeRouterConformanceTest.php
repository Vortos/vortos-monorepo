<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Conformance;

use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Testing\EdgeRouterConformanceTestCase;
use Vortos\DeployK8s\Edge\KubernetesEdgeRouter;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;

final class KubernetesEdgeRouterConformanceTest extends EdgeRouterConformanceTestCase
{
    private FakeKubeApi $kubeApi;

    protected function setUp(): void
    {
        $this->kubeApi = new FakeKubeApi();
        $this->kubeApi->seedService('app', 'default', ['app.kubernetes.io/color' => 'blue'], '1', 8080);
    }

    protected function createRouter(): EdgeRouterInterface
    {
        return new KubernetesEdgeRouter($this->kubeApi, 'app', 'default');
    }

    protected function expectedKey(): string
    {
        return 'k8s';
    }
}
