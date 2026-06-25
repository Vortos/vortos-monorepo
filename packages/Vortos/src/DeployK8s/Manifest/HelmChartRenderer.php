<?php

declare(strict_types=1);

namespace Vortos\DeployK8s\Manifest;

use Vortos\Docker\Worker\WorkerProcessRegistry;

final class HelmChartRenderer
{
    public function __construct(
        private readonly KubernetesManifestRenderer $renderer,
    ) {}

    /**
     * @return array<string, string> filename → content
     */
    public function render(WorkerProcessRegistry $workers, RenderContext $ctx): array
    {
        $chart = [];

        $chart['Chart.yaml'] = $this->renderChartYaml($ctx);
        $chart['values.yaml'] = $this->renderValuesYaml($ctx, $workers);

        $objects = $this->renderer->render($workers, $ctx);
        foreach ($objects as $object) {
            $filename = 'templates/' . strtolower($object->kind) . '-' . $object->name . '.json';
            $chart[$filename] = $object->toJson();
        }

        return $chart;
    }

    private function renderChartYaml(RenderContext $ctx): string
    {
        $data = [
            'apiVersion' => 'v2',
            'name' => $ctx->appName,
            'description' => 'Auto-generated Helm chart by vortos-deploy-k8s',
            'type' => 'application',
            'version' => '0.1.0',
            'appVersion' => '1.0.0',
        ];

        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }

    private function renderValuesYaml(RenderContext $ctx, WorkerProcessRegistry $workers): string
    {
        $values = [
            'namespace' => $ctx->namespace,
            'image' => $ctx->imageReference,
            'replicas' => $ctx->replicas,
            'port' => $ctx->port,
            'healthPath' => $ctx->healthPath,
            'livePath' => $ctx->livePath,
            'hpa' => [
                'minReplicas' => $ctx->minReplicas,
                'maxReplicas' => $ctx->maxReplicas,
                'cpuTargetPercent' => $ctx->cpuTargetPercent,
            ],
            'workers' => [],
        ];

        foreach ($workers->all() as $worker) {
            $values['workers'][] = [
                'name' => $worker->name,
                'command' => $worker->command,
                'replicas' => $worker->numprocs,
                'drainDeadline' => $worker->drainDeadline,
            ];
        }

        return json_encode($values, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
