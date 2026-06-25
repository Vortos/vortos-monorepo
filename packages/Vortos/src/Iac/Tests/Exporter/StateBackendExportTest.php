<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Lifecycle\StateBackend\StateBackendExporter;

final class StateBackendExportTest extends TestCase
{
    public function test_s3_backend_has_encrypt(): void
    {
        $exporter = new StateBackendExporter();
        $doc = $exporter->export([
            'spec' => ['provider' => 's3', 'bucket' => 'tf-state', 'key' => 'app/terraform.tfstate', 'region' => 'eu-west-1', 'dynamodb_table' => 'tf-locks'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(), true);
        $backend = $decoded['terraform']['backend']['s3'];
        $this->assertSame('tf-state', $backend['bucket']);
        $this->assertTrue($backend['encrypt']);
        $this->assertSame('tf-locks', $backend['dynamodb_table']);
    }

    public function test_gcs_backend(): void
    {
        $exporter = new StateBackendExporter();
        $doc = $exporter->export([
            'spec' => ['provider' => 'gcs', 'bucket' => 'tf-state-gcs', 'prefix' => 'app'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(), true);
        $backend = $decoded['terraform']['backend']['gcs'];
        $this->assertSame('tf-state-gcs', $backend['bucket']);
        $this->assertSame('app', $backend['prefix']);
    }

    public function test_local_backend(): void
    {
        $exporter = new StateBackendExporter();
        $doc = $exporter->export([
            'spec' => ['provider' => 'local', 'path' => 'terraform.tfstate'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(), true);
        $this->assertSame('terraform.tfstate', $decoded['terraform']['backend']['local']['path']);
    }

    public function test_count_resources_is_zero(): void
    {
        $exporter = new StateBackendExporter();
        $this->assertSame(0, $exporter->countResources(['spec' => ['provider' => 's3']]));
    }
}
