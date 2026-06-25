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

final class DigestPinnedManifestTest extends TestCase
{
    private KubernetesManifestRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());
    }

    public function test_app_deployment_image_is_digest_pinned(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $ctx = new RenderContext(imageReference: 'myapp@' . $digest);
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);

        foreach ($objects as $obj) {
            if ($obj->kind !== 'Deployment') {
                continue;
            }
            $containers = $obj->spec['spec']['template']['spec']['containers'] ?? [];
            foreach ($containers as $container) {
                $image = $container['image'] ?? '';
                $this->assertStringContainsString(
                    '@sha256:',
                    $image,
                    sprintf('Container image in %s must be digest-pinned, got "%s".', $obj->name, $image),
                );
            }
        }
    }

    public function test_migration_job_image_is_digest_pinned(): void
    {
        $digest = 'sha256:' . str_repeat('b', 64);
        $ctx = new RenderContext(imageReference: 'myapp@' . $digest);

        $job = $this->renderer->renderMigrationJob($ctx, ['php', 'migrate']);
        $containers = $job->spec['spec']['template']['spec']['containers'] ?? [];

        foreach ($containers as $container) {
            $this->assertStringContainsString('@sha256:', $container['image'] ?? '');
        }
    }

    public function test_worker_deployment_image_is_digest_pinned(): void
    {
        $digest = 'sha256:' . str_repeat('c', 64);
        $ctx = new RenderContext(imageReference: 'myapp@' . $digest);
        $workers = new WorkerProcessRegistry([
            new WorkerProcessDefinition('worker-a', 'php worker', 'test'),
        ]);

        $objects = $this->renderer->render($workers, $ctx);
        $workerDeploy = null;
        foreach ($objects as $obj) {
            if ($obj->name === 'worker-worker-a') {
                $workerDeploy = $obj;
            }
        }

        $this->assertNotNull($workerDeploy);
        $container = $workerDeploy->spec['spec']['template']['spec']['containers'][0];
        $this->assertStringContainsString('@sha256:', $container['image']);
    }
}
