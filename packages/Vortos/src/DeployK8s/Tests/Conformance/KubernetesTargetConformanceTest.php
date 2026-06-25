<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Conformance;

use Vortos\Deploy\Plan\DeployPlanner;
use Vortos\Deploy\Strategy\BlueGreenStrategy;
use Vortos\Deploy\Strategy\DeployStrategyRegistry;
use Vortos\Deploy\Target\DeployCapability;
use Vortos\Deploy\Target\DeployTargetInterface;
use Vortos\Deploy\Testing\DeployTargetConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeContainerRegistry;
use Vortos\Deploy\Tests\Fixtures\FakeDeployStateStore;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Target\KubernetesStepExecutor;
use Vortos\DeployK8s\Target\KubernetesTarget;
use Vortos\DeployK8s\Tests\Fixtures\FakeKubeApi;

final class KubernetesTargetConformanceTest extends DeployTargetConformanceTestCase
{
    protected function createTarget(): DeployTargetInterface
    {
        $strategyRegistry = new DeployStrategyRegistry();
        $strategyRegistry->register(new BlueGreenStrategy());

        $stateStore = new FakeDeployStateStore();
        $registry = new FakeContainerRegistry();
        $kubeApi = new FakeKubeApi();

        $renderer = new KubernetesManifestRenderer(
            new PodSecurityProfile(),
            new RbacRenderer(),
        );

        $executor = new KubernetesStepExecutor(
            kubeApi: $kubeApi,
            stateStore: $stateStore,
            renderer: $renderer,
        );

        return new KubernetesTarget(
            planner: new DeployPlanner($strategyRegistry),
            executor: $executor,
            registry: $registry,
            stateStore: $stateStore,
            kubeApi: $kubeApi,
        );
    }

    protected function expectedKey(): string
    {
        return 'k8s';
    }

    public function test_honestly_supports_rolling_across_nodes(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertTrue(
            $descriptor->supports(DeployCapability::RollingAcrossNodes),
            'k8s must support rolling across nodes — the headline capability difference from ssh-compose.',
        );
    }

    public function test_honestly_supports_canary(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertTrue($descriptor->supports(DeployCapability::Canary));
    }

    public function test_honestly_unsupported_accepts_downtime(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertHonestlyUnsupported($descriptor, DeployCapability::AcceptsDowntime);
    }

    public function test_no_target_arch_constraint(): void
    {
        $descriptor = $this->createTarget()->capabilities();
        $this->assertNull(
            $descriptor->constraint('target_arch'),
            'k8s must not pin target_arch — clusters are multi-arch.',
        );
    }
}
