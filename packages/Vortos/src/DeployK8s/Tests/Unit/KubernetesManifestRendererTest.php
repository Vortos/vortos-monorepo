<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class KubernetesManifestRendererTest extends TestCase
{
    private KubernetesManifestRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());
    }

    public function test_empty_worker_registry_renders_app_only(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);

        $kinds = array_map(fn ($o) => $o->kind, $objects);
        $this->assertContains('Deployment', $kinds);
        $this->assertContains('Service', $kinds);
        $this->assertContains('HorizontalPodAutoscaler', $kinds);
        $this->assertContains('PodDisruptionBudget', $kinds);
        $this->assertContains('Role', $kinds);
        $this->assertContains('RoleBinding', $kinds);

        $workerDeploys = array_filter($objects, fn ($o) => $o->kind === 'Deployment' && str_starts_with($o->name, 'worker-'));
        $this->assertEmpty($workerDeploys, 'No worker deployments should be rendered with empty registry.');
    }

    public function test_worker_deployment_replicas_equals_numprocs(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([
            new WorkerProcessDefinition('queue', 'php worker', 'Queue', numprocs: 5),
        ]);

        $objects = $this->renderer->render($workers, $ctx);

        $workerDeploy = null;
        foreach ($objects as $obj) {
            if ($obj->name === 'worker-queue') {
                $workerDeploy = $obj;
            }
        }

        $this->assertNotNull($workerDeploy);
        $this->assertSame(5, $workerDeploy->spec['spec']['replicas']);
    }

    public function test_worker_termination_grace_period_from_drain_deadline(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([
            new WorkerProcessDefinition('mailer', 'php mail', 'Mailer', stopwaitsecs: 60, drainDeadline: 45),
        ]);

        $objects = $this->renderer->render($workers, $ctx);

        $workerDeploy = null;
        foreach ($objects as $obj) {
            if ($obj->name === 'worker-mailer') {
                $workerDeploy = $obj;
            }
        }

        $this->assertNotNull($workerDeploy);
        $grace = $workerDeploy->spec['spec']['template']['spec']['terminationGracePeriodSeconds'];
        $this->assertSame(60, $grace, 'grace = max(stopwaitsecs, drainDeadline)');
    }

    public function test_service_selector_defaults_to_blue(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);

        $svc = null;
        foreach ($objects as $obj) {
            if ($obj->kind === 'Service') {
                $svc = $obj;
            }
        }

        $this->assertNotNull($svc);
        $this->assertSame('blue', $svc->spec['spec']['selector']['app.kubernetes.io/color']);
    }

    public function test_color_label_on_app_deployment(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $greenObjects = $this->renderer->render($workers, $ctx, 'green');

        $deployment = null;
        foreach ($greenObjects as $obj) {
            if ($obj->kind === 'Deployment' && str_starts_with($obj->name, 'app-')) {
                $deployment = $obj;
            }
        }

        $this->assertNotNull($deployment);
        $this->assertSame('app-green', $deployment->name);
        $labels = $deployment->spec['metadata']['labels'];
        $this->assertSame('green', $labels['app.kubernetes.io/color']);
    }

    public function test_namespace_propagated_to_all_objects(): void
    {
        $ctx = new RenderContext(namespace: 'staging', imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);

        foreach ($objects as $obj) {
            $this->assertSame('staging', $obj->namespace, sprintf('%s/%s has wrong namespace', $obj->kind, $obj->name));
        }
    }

    public function test_migration_job_has_backoff_limit_zero(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $job = $this->renderer->renderMigrationJob($ctx, ['php', 'migrate']);

        $this->assertSame('Job', $job->kind);
        $this->assertSame(0, $job->spec['spec']['backoffLimit']);
        $this->assertSame('Never', $job->spec['spec']['template']['spec']['restartPolicy']);
    }

    public function test_secret_refs_injected_via_env_from(): void
    {
        $ctx = new RenderContext(
            imageReference: 'app@sha256:' . str_repeat('a', 64),
            secretRefs: ['app-secrets', 'db-secrets'],
        );
        $workers = new WorkerProcessRegistry([]);

        $objects = $this->renderer->render($workers, $ctx);

        $deployment = null;
        foreach ($objects as $obj) {
            if ($obj->kind === 'Deployment' && str_starts_with($obj->name, 'app-')) {
                $deployment = $obj;
            }
        }

        $this->assertNotNull($deployment);
        $envFrom = $deployment->spec['spec']['template']['spec']['containers'][0]['envFrom'];
        $this->assertCount(2, $envFrom);
        $this->assertSame('app-secrets', $envFrom[0]['secretRef']['name']);
        $this->assertSame('db-secrets', $envFrom[1]['secretRef']['name']);
    }

    public function test_rolling_update_strategy_maxunavailable_zero(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);
        $objects = $this->renderer->render($workers, $ctx);

        $deployment = null;
        foreach ($objects as $obj) {
            if ($obj->kind === 'Deployment' && str_starts_with($obj->name, 'app-')) {
                $deployment = $obj;
            }
        }

        $this->assertNotNull($deployment);
        $strategy = $deployment->spec['spec']['strategy'];
        $this->assertSame('RollingUpdate', $strategy['type']);
        $this->assertSame(0, $strategy['rollingUpdate']['maxUnavailable']);
        $this->assertSame(1, $strategy['rollingUpdate']['maxSurge']);
    }
}
