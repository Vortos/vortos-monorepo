<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class PodSecurityRestrictedTest extends TestCase
{
    private KubernetesManifestRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());
    }

    public function test_app_deployment_uses_restricted_security_context(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);
        $deployment = $this->findByKind($objects, 'Deployment');
        $this->assertNotNull($deployment);

        $container = $deployment->spec['spec']['template']['spec']['containers'][0];
        $sc = $container['securityContext'];

        $this->assertTrue($sc['runAsNonRoot']);
        $this->assertGreaterThan(0, $sc['runAsUser']);
        $this->assertFalse($sc['allowPrivilegeEscalation']);
        $this->assertTrue($sc['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $sc['capabilities']['drop']);
        $this->assertSame('RuntimeDefault', $sc['seccompProfile']['type']);
    }

    public function test_worker_deployment_uses_restricted_security_context(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([
            new WorkerProcessDefinition('queue-worker', 'php artisan queue:work', 'Queue worker'),
        ]);

        $objects = $this->renderer->render($workers, $ctx);
        $workerDeploy = $this->findByName($objects, 'worker-queue-worker');
        $this->assertNotNull($workerDeploy);

        $container = $workerDeploy->spec['spec']['template']['spec']['containers'][0];
        $sc = $container['securityContext'];

        $this->assertTrue($sc['runAsNonRoot']);
        $this->assertFalse($sc['allowPrivilegeEscalation']);
        $this->assertTrue($sc['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $sc['capabilities']['drop']);
    }

    public function test_migration_job_uses_restricted_security_context(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $job = $this->renderer->renderMigrationJob($ctx, ['php', 'migrate']);

        $container = $job->spec['spec']['template']['spec']['containers'][0];
        $sc = $container['securityContext'];

        $this->assertTrue($sc['runAsNonRoot']);
        $this->assertFalse($sc['allowPrivilegeEscalation']);
        $this->assertTrue($sc['readOnlyRootFilesystem']);
        $this->assertSame(['ALL'], $sc['capabilities']['drop']);
    }

    public function test_pod_security_context_on_all_pods(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);
        $objects = $this->renderer->render($workers, $ctx);

        foreach ($objects as $obj) {
            if ($obj->kind !== 'Deployment') {
                continue;
            }
            $podSec = $obj->spec['spec']['template']['spec']['securityContext'] ?? null;
            $this->assertNotNull($podSec, sprintf('%s must have pod securityContext', $obj->name));
            $this->assertTrue($podSec['runAsNonRoot']);
        }
    }

    /** @param list<\Vortos\DeployK8s\Api\KubeObject> $objects */
    private function findByKind(array $objects, string $kind): ?\Vortos\DeployK8s\Api\KubeObject
    {
        foreach ($objects as $obj) {
            if ($obj->kind === $kind) {
                return $obj;
            }
        }
        return null;
    }

    /** @param list<\Vortos\DeployK8s\Api\KubeObject> $objects */
    private function findByName(array $objects, string $name): ?\Vortos\DeployK8s\Api\KubeObject
    {
        foreach ($objects as $obj) {
            if ($obj->name === $name) {
                return $obj;
            }
        }
        return null;
    }
}
