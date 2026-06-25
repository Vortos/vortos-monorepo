<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Network;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class NetworkExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = NetworkProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            NetworkProvider::Aws => $this->exportAws($spec, $document),
            NetworkProvider::Gcp => $this->exportGcp($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        $subnets = count($entry['spec']['subnet_cidrs'] ?? []);
        return 1 + max(1, $subnets);
    }

    /** @param array<string, mixed> $spec */
    private function exportAws(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $label = $spec['label'];

        $document->resource('aws_vpc', $label, [
            'cidr_block' => $spec['vpc_cidr'] ?? '10.0.0.0/16',
            'enable_dns_hostnames' => true,
            'enable_dns_support' => true,
        ]);

        foreach ($spec['subnet_cidrs'] ?? ['10.0.1.0/24'] as $i => $cidr) {
            $document->resource('aws_subnet', $label . '_' . $i, [
                'vpc_id' => SpecValue::decode(SpecValue::ref('aws_vpc.' . $label . '.id'), $document),
                'cidr_block' => $cidr,
            ]);
        }
    }

    /** @param array<string, mixed> $spec */
    private function exportGcp(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $label = $spec['label'];

        $document->resource('google_compute_network', $label, [
            'name' => $label,
            'auto_create_subnetworks' => false,
        ]);

        foreach ($spec['subnet_cidrs'] ?? ['10.0.1.0/24'] as $i => $cidr) {
            $attrs = [
                'name' => $label . '-subnet-' . $i,
                'network' => SpecValue::decode(SpecValue::ref('google_compute_network.' . $label . '.id'), $document),
                'ip_cidr_range' => $cidr,
            ];
            if (isset($spec['region'])) {
                $attrs['region'] = SpecValue::decode($spec['region'], $document);
            }
            $document->resource('google_compute_subnetwork', $label . '_' . $i, $attrs);
        }
    }
}
