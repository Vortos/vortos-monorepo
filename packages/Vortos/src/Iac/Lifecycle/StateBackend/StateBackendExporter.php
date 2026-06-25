<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle\StateBackend;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Terraform\TerraformDocument;

final class StateBackendExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = $spec['provider'];
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        $config = match ($provider) {
            's3' => $this->s3Config($spec),
            'gcs' => $this->gcsConfig($spec),
            'local' => ['path' => $spec['path'] ?? 'terraform.tfstate'],
            default => throw new \LogicException(sprintf("Unknown state backend provider '%s'.", $provider)),
        };

        $document->backend($provider, $config);

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        return 0;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function s3Config(array $spec): array
    {
        $config = [
            'bucket' => $spec['bucket'],
            'key' => $spec['key'] ?? 'terraform.tfstate',
        ];

        if (isset($spec['region'])) {
            $config['region'] = $spec['region'];
        }

        if (isset($spec['dynamodb_table'])) {
            $config['dynamodb_table'] = $spec['dynamodb_table'];
        }

        $config['encrypt'] = true;

        return $config;
    }

    /**
     * @param array<string, mixed> $spec
     * @return array<string, mixed>
     */
    private function gcsConfig(array $spec): array
    {
        $config = ['bucket' => $spec['bucket']];

        if (isset($spec['prefix'])) {
            $config['prefix'] = $spec['prefix'];
        }

        return $config;
    }
}
