<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vortos\DeployK8s\Manifest\HelmChartRenderer;
use Vortos\DeployK8s\Manifest\KubernetesManifestRenderer;
use Vortos\DeployK8s\Manifest\PodSecurityProfile;
use Vortos\DeployK8s\Manifest\RbacRenderer;
use Vortos\DeployK8s\Manifest\RenderContext;
use Vortos\Docker\Worker\WorkerProcessDefinition;
use Vortos\Docker\Worker\WorkerProcessRegistry;

final class HelmChartRendererTest extends TestCase
{
    private HelmChartRenderer $renderer;

    protected function setUp(): void
    {
        $manifest = new KubernetesManifestRenderer(new PodSecurityProfile(), new RbacRenderer());
        $this->renderer = new HelmChartRenderer($manifest);
    }

    public function test_chart_yaml_present(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $chart = $this->renderer->render($workers, $ctx);

        $this->assertArrayHasKey('Chart.yaml', $chart);

        $data = json_decode($chart['Chart.yaml'], true);
        $this->assertSame('v2', $data['apiVersion']);
        $this->assertSame('app', $data['name']);
    }

    public function test_values_yaml_present(): void
    {
        $ctx = new RenderContext(
            namespace: 'prod',
            imageReference: 'app@sha256:' . str_repeat('a', 64),
            replicas: 3,
        );
        $workers = new WorkerProcessRegistry([]);

        $chart = $this->renderer->render($workers, $ctx);

        $this->assertArrayHasKey('values.yaml', $chart);
        $values = json_decode($chart['values.yaml'], true);
        $this->assertSame('prod', $values['namespace']);
        $this->assertSame(3, $values['replicas']);
    }

    public function test_templates_include_deployment_and_service(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([]);

        $chart = $this->renderer->render($workers, $ctx);

        $templateKeys = array_filter(array_keys($chart), fn ($k) => str_starts_with($k, 'templates/'));
        $this->assertNotEmpty($templateKeys);

        $hasDeployment = false;
        $hasService = false;
        foreach ($templateKeys as $key) {
            if (str_contains($key, 'deployment')) {
                $hasDeployment = true;
            }
            if (str_contains($key, 'service')) {
                $hasService = true;
            }
        }

        $this->assertTrue($hasDeployment, 'Chart must include a Deployment template.');
        $this->assertTrue($hasService, 'Chart must include a Service template.');
    }

    public function test_workers_included_in_values(): void
    {
        $ctx = new RenderContext(imageReference: 'app@sha256:' . str_repeat('a', 64));
        $workers = new WorkerProcessRegistry([
            new WorkerProcessDefinition('queue-worker', 'php artisan queue:work', 'Queue', numprocs: 3),
        ]);

        $chart = $this->renderer->render($workers, $ctx);
        $values = json_decode($chart['values.yaml'], true);

        $this->assertCount(1, $values['workers']);
        $this->assertSame('queue-worker', $values['workers'][0]['name']);
        $this->assertSame(3, $values['workers'][0]['replicas']);
    }
}
