<?php

declare(strict_types=1);

namespace Vortos\Iac\Exporter\ObjectStore;

use Vortos\Iac\Export\ExporterInterface;
use Vortos\Iac\Export\SpecValue;
use Vortos\Iac\Terraform\TerraformDocument;

/** Runtime half of the object-store bucket export. Pure transform. */
final class ObjectStoreBucketExporter implements ExporterInterface
{
    public function export(array $entry): TerraformDocument
    {
        $spec = $entry['spec'];
        $document = new TerraformDocument($entry['allowed_literals'] ?? []);

        switch (ObjectStoreProvider::from($spec['provider'])) {
            case ObjectStoreProvider::Aws:
                $document->requiredProvider('aws', 'hashicorp/aws', '~> 5.0');
                $document->resource('aws_s3_bucket', $spec['label'], [
                    'bucket' => SpecValue::decode($spec['bucket'], $document),
                ]);
                break;

            case ObjectStoreProvider::CloudflareR2:
                $document->requiredProvider('cloudflare', 'cloudflare/cloudflare', '~> 4.0');
                $attributes = [
                    'account_id' => SpecValue::decode($spec['account_id'], $document),
                    'name' => SpecValue::decode($spec['bucket'], $document),
                ];

                $location = SpecValue::decode($spec['region'], $document);
                if ($location !== null && $location !== '') {
                    $attributes['location'] = $location;
                }

                $document->resource('cloudflare_r2_bucket', $spec['label'], $attributes);
                break;
        }

        return $document;
    }

    public function countResources(array $entry): int
    {
        return 1;
    }
}
