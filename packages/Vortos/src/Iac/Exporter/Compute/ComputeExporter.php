<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Compute;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class ComputeExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = ComputeProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            ComputeProvider::Aws => $this->exportAws($spec, $document),
            ComputeProvider::Gcp => $this->exportGcp($spec, $document),
            ComputeProvider::GenericVps => $this->exportGeneric($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        return 1;
    }

    /** @param array<string, mixed> $spec */
    private function exportAws(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');

        $attrs = [];
        if (isset($spec['ami'])) {
            $attrs['ami'] = SpecValue::decode($spec['ami'], $document);
        }
        if (isset($spec['instance_type'])) {
            $attrs['instance_type'] = SpecValue::decode($spec['instance_type'], $document);
        }
        if (isset($spec['key_name'])) {
            $attrs['key_name'] = SpecValue::decode($spec['key_name'], $document);
        }
        if (isset($spec['subnet_ref'])) {
            $attrs['subnet_id'] = SpecValue::decode(SpecValue::ref($spec['subnet_ref'] . '.id'), $document);
        }

        $document->resource('aws_instance', $spec['label'], $attrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportGcp(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');

        $attrs = [];
        if (isset($spec['machine_type'])) {
            $attrs['machine_type'] = SpecValue::decode($spec['machine_type'], $document);
        }
        if (isset($spec['zone'])) {
            $attrs['zone'] = SpecValue::decode($spec['zone'], $document);
        }
        if (isset($spec['image'])) {
            $attrs['boot_disk'] = ['initialize_params' => ['image' => SpecValue::decode($spec['image'], $document)]];
        }

        $attrs['name'] = $spec['label'];
        $document->resource('google_compute_instance', $spec['label'], $attrs);
    }

    /** @param array<string, mixed> $spec */
    private function exportGeneric(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('null', 'hashicorp/null', '~> 3.0');

        $attrs = ['triggers' => ['label' => $spec['label']]];

        if (isset($spec['instance_type'])) {
            $attrs['triggers']['instance_type'] = SpecValue::decode($spec['instance_type'], $document);
        }

        $document->resource('null_resource', $spec['label'], $attrs);
    }
}
