<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Database;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class DatabaseExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = DatabaseProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            DatabaseProvider::AwsRds => $this->exportRds($spec, $document),
            DatabaseProvider::GcpCloudSql => $this->exportCloudSql($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int { return 1; }

    /** @param array<string, mixed> $spec */
    private function exportRds(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $attrs = [
            'identifier' => $spec['label'],
            'engine' => $spec['engine'] ?? 'postgres',
            'engine_version' => $spec['engine_version'] ?? '16',
            'instance_class' => SpecValue::decode($spec['instance_class'] ?? 'db.t3.micro', $document),
            'allocated_storage' => $spec['allocated_storage'] ?? 20,
            'skip_final_snapshot' => true,
        ];
        $document->resource('aws_db_instance', $spec['label'], $attrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportCloudSql(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $attrs = [
            'name' => $spec['label'],
            'database_version' => strtoupper(($spec['engine'] ?? 'POSTGRES') . '_' . ($spec['engine_version'] ?? '16')),
            'settings' => ['tier' => SpecValue::decode($spec['tier'] ?? 'db-f1-micro', $document)],
        ];
        if (isset($spec['region'])) {
            $attrs['region'] = SpecValue::decode($spec['region'], $document);
        }
        $document->resource('google_sql_database_instance', $spec['label'], $attrs);
    }
}
