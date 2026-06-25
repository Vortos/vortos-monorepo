<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exporter\Compute\ComputeExporter;

final class ComputeExportTest extends TestCase
{
    public function test_aws_ec2_instance(): void
    {
        $doc = (new ComputeExporter())->export([
            'spec' => ['provider' => 'aws', 'label' => 'web', 'ami' => 'ami-12345', 'instance_type' => 't3.micro'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('aws_instance', $decoded['resource']);
        $this->assertSame('ami-12345', $decoded['resource']['aws_instance']['web']['ami']);
    }

    public function test_gcp_compute_instance(): void
    {
        $doc = (new ComputeExporter())->export([
            'spec' => ['provider' => 'gcp', 'label' => 'web', 'machine_type' => 'e2-micro', 'zone' => 'us-central1-a'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('google_compute_instance', $decoded['resource']);
        $this->assertSame('e2-micro', $decoded['resource']['google_compute_instance']['web']['machine_type']);
    }

    public function test_generic_vps_uses_null_resource(): void
    {
        $doc = (new ComputeExporter())->export([
            'spec' => ['provider' => 'generic-vps', 'label' => 'vps'],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('null_resource', $decoded['resource']);
    }
}
