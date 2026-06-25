<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Api\KubeObject;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class HighAvailabilityManifestTest extends TestCase
{
    private KubernetesManifestRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());
    }

    public function test_app_deployment_has_at_least_two_replicas(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64), replicas: 1);
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);
        $deployment = $this->findByKindAndNamePrefix($objects, 'Deployment', 'app-');

        $this->assertNotNull($deployment);
        $replicas = $deployment->spec['spec']['replicas'];
        $this->assertGreaterThanOrEqual(2, $replicas, 'HA: app deployment must have at least 2 replicas.');
    }

    public function test_pdb_is_emitted(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);
        $pdb = $this->findByKind($objects, 'PodDisruptionBudget');

        $this->assertNotNull($pdb, 'A PodDisruptionBudget must be emitted for HA.');
        $this->assertArrayHasKey('maxUnavailable', $pdb->spec['spec']);
    }

    public function test_topology_spread_constraints_present(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);
        $deployment = $this->findByKindAndNamePrefix($objects, 'Deployment', 'app-');

        $this->assertNotNull($deployment);
        $spread = $deployment->spec['spec']['template']['spec']['topologySpreadConstraints'] ?? [];
        $this->assertNotEmpty($spread, 'HA: topologySpreadConstraints must be set.');

        $keys = array_column($spread, 'topologyKey');
        $this->assertContains('kubernetes.io/hostname', $keys);
    }

    public function test_hpa_is_emitted_with_min_replicas_at_least_two(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);
        $hpa = $this->findByKind($objects, 'HorizontalPodAutoscaler');

        $this->assertNotNull($hpa, 'An HPA must be emitted.');
        $this->assertGreaterThanOrEqual(2, $hpa->spec['spec']['minReplicas']);
    }

    /** @param list<KubeObject> $objects */
    private function findByKind(array $objects, string $kind): ?KubeObject
    {
        foreach ($objects as $obj) {
            if ($obj->kind === $kind) {
                return $obj;
            }
        }
        return null;
    }

    /** @param list<KubeObject> $objects */
    private function findByKindAndNamePrefix(array $objects, string $kind, string $prefix): ?KubeObject
    {
        foreach ($objects as $obj) {
            if ($obj->kind === $kind && str_starts_with($obj->name, $prefix)) {
                return $obj;
            }
        }
        return null;
    }
}
