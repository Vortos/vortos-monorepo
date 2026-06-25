<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\Dns;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

final class DnsExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $provider = DnsProvider::from($spec['provider']);
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        match ($provider) {
            DnsProvider::AwsRoute53 => $this->exportRoute53($spec, $document),
            DnsProvider::Cloudflare => $this->exportCloudflare($spec, $document),
            DnsProvider::Gcp => $this->exportGcp($spec, $document),
        };

        return $document;
    }

    /** @param array<string, mixed> $entry */
    public function countResources(array $entry): int
    {
        $count = count($entry['spec']['records'] ?? []);
        if (!empty($entry['spec']['zone_name'])) { $count++; }
        if (!empty($entry['spec']['managed_cert'])) { $count++; }
        return max(1, $count);
    }

    /** @param array<string, mixed> $spec */
    private function exportRoute53(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
        $label = $spec['label'];

        if (!empty($spec['zone_name'])) {
            $document->resource('aws_route53_zone', $label, ['name' => $spec['zone_name']]);
        }

        foreach ($spec['records'] ?? [] as $i => $record) {
            $recLabel = $label . '_r' . $i;
            $zoneRef = isset($spec['zone_id'])
                ? SpecValue::decode($spec['zone_id'], $document)
                : SpecValue::decode(SpecValue::ref('aws_route53_zone.' . $label . '.zone_id'), $document);
            $document->resource('aws_route53_record', $recLabel, [
                'zone_id' => $zoneRef,
                'name' => $record['name'],
                'type' => $record['type'],
                'ttl' => $record['ttl'] ?? 300,
                'records' => [$record['value']],
            ]);
        }

        if (!empty($spec['managed_cert']) && isset($spec['cert_domain'])) {
            $document->resource('aws_acm_certificate', $label . '_cert', [
                'domain_name' => $spec['cert_domain'],
                'validation_method' => 'DNS',
            ]);
        }
    }

    /** @param array<string, mixed> $spec */
    private function exportCloudflare(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('cloudflare', 'cloudflare/cloudflare', '~> 4.0');
        $label = $spec['label'];

        if (!empty($spec['zone_name'])) {
            $document->resource('cloudflare_zone', $label, ['zone' => $spec['zone_name']]);
        }

        foreach ($spec['records'] ?? [] as $i => $record) {
            $recLabel = $label . '_r' . $i;
            $zoneRef = isset($spec['zone_id'])
                ? SpecValue::decode($spec['zone_id'], $document)
                : SpecValue::decode(SpecValue::ref('cloudflare_zone.' . $label . '.id'), $document);
            $document->resource('cloudflare_record', $recLabel, [
                'zone_id' => $zoneRef,
                'name' => $record['name'],
                'type' => $record['type'],
                'value' => $record['value'],
                'ttl' => $record['ttl'] ?? 300,
            ]);
        }
    }

    /** @param array<string, mixed> $spec */
    private function exportGcp(array $spec, TerraformDocument $document): void
    {
        $document->requiredProvider('google', 'hashicorp/google', '~> 5.0');
        $label = $spec['label'];

        if (!empty($spec['zone_name'])) {
            $document->resource('google_dns_managed_zone', $label, [
                'name' => $label,
                'dns_name' => $spec['zone_name'] . '.',
            ]);
        }

        foreach ($spec['records'] ?? [] as $i => $record) {
            $recLabel = $label . '_r' . $i;
            $zoneRef = SpecValue::decode(SpecValue::ref('google_dns_managed_zone.' . $label . '.name'), $document);
            $document->resource('google_dns_record_set', $recLabel, [
                'managed_zone' => $zoneRef,
                'name' => $record['name'] . '.',
                'type' => $record['type'],
                'ttl' => $record['ttl'] ?? 300,
                'rrdatas' => [$record['value']],
            ]);
        }

        if (!empty($spec['managed_cert']) && isset($spec['cert_domain'])) {
            $document->resource('google_compute_managed_ssl_certificate', $label . '_cert', [
                'name' => $label . '-cert',
                'managed' => ['domains' => [$spec['cert_domain']]],
            ]);
        }
    }
}
