<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Cache;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class CacheExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = CacheProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            CacheProvider::AwsElasticache => $this->exportElasticache($spec, $document),
            CacheProvider::GcpMemorystore => $this->exportMemorystore($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int { return 1; }

    /** @param array<string, mixed> $spec */
    private function exportElasticache(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $attrs = [
            'cluster_id' => $spec['label'],
            'engine' => 'redis',
            'node_type' => SpecValue::decode($spec['node_type'] ?? 'cache.t3.micro', $document),
            'num_cache_nodes' => $spec['num_cache_nodes'] ?? 1,
        ];
        if (isset($spec['engine_version'])) {
            $attrs['engine_version'] = $spec['engine_version'];
        }
        $document->resource('aws_elasticache_cluster', $spec['label'], $attrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportMemorystore(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $attrs = [
            'name' => $spec['label'],
            'tier' => 'BASIC',
            'memory_size_gb' => $spec['memory_size_gb'] ?? 1,
        ];
        if (isset($spec['region'])) {
            $attrs['region'] = SpecValue::decode($spec['region'], $document);
        }
        if (isset($spec['engine_version'])) {
            $attrs['redis_version'] = 'REDIS_' . str_replace('.', '_', $spec['engine_version']);
        }
        $document->resource('google_redis_instance', $spec['label'], $attrs);
    }
}
