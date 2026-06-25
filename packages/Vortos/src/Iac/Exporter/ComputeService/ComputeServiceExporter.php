<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ComputeService;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class ComputeServiceExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = ComputeServiceProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            ComputeServiceProvider::AwsEcs => $this->exportEcs($spec, $document),
            ComputeServiceProvider::GcpCloudRun => $this->exportCloudRun($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        return match (ComputeServiceProvider::from($entry['spec']['provider'])) {
            ComputeServiceProvider::AwsEcs => 2,
            ComputeServiceProvider::GcpCloudRun => 1,
        };
    }

    /** @param array<string, mixed> $spec */
    private function exportEcs(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $label = $spec['label'];

        $containerDef = [
            'name' => $label,
            'image' => SpecValue::decode($spec['container_image'] ?? 'app:latest', $document),
            'essential' => true,
        ];
        if (isset($spec['container_port'])) {
            $containerDef['portMappings'] = [['containerPort' => $spec['container_port']]];
        }

        $document->resource('aws_ecs_task_definition', $label, [
            'family' => $label,
            'requires_compatibilities' => ['FARGATE'],
            'network_mode' => 'awsvpc',
            'cpu' => (string) ($spec['cpu'] ?? 256),
            'memory' => (string) ($spec['memory'] ?? 512),
            'container_definitions' => json_encode([$containerDef]),
        ]);

        $serviceAttrs = [
            'name' => $label,
            'launch_type' => 'FARGATE',
            'desired_count' => 1,
        ];

        if (isset($spec['cluster_ref'])) {
            $serviceAttrs['cluster'] = SpecValue::decode(SpecValue::ref($spec['cluster_ref'] . '.id'), $document);
        }

        $document->resource('aws_ecs_service', $label, $serviceAttrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportCloudRun(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $label = $spec['label'];

        $attrs = [
            'name' => $label,
            'location' => SpecValue::decode($spec['region'] ?? 'us-central1', $document),
            'template' => [
                'spec' => [
                    'containers' => [[
                        'image' => SpecValue::decode($spec['container_image'] ?? 'app:latest', $document),
                    ]],
                ],
            ],
        ];

        $document->resource('google_cloud_run_service', $label, $attrs);
    }
}
