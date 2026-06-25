<?php

declare(strict_types=1);

namespace Vortos\Iac\Tests\Exporter;

use PHPUnit\Framework\TestCase;
use Vortos\Iac\Exporter\Network\NetworkExporter;

final class NetworkExportTest extends TestCase
{
    public function test_aws_vpc_and_subnets(): void
    {
        $doc = (new NetworkExporter())->export([
            'spec' => ['provider' => 'aws', 'label' => 'main', 'vpc_cidr' => '10.0.0.0/16', 'subnet_cidrs' => ['10.0.1.0/24', '10.0.2.0/24']],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('aws_vpc', $decoded['resource']);
        $this->assertArrayHasKey('aws_subnet', $decoded['resource']);
        $this->assertCount(2, $decoded['resource']['aws_subnet']);
    }

    public function test_gcp_network_and_subnetwork(): void
    {
        $doc = (new NetworkExporter())->export([
            'spec' => ['provider' => 'gcp', 'label' => 'main', 'subnet_cidrs' => ['10.0.1.0/24']],
            'allowed_literals' => [],
        ]);

        $decoded = json_decode($doc->render(includeVariables: false), true);
        $this->assertArrayHasKey('google_compute_network', $decoded['resource']);
        $this->assertArrayHasKey('google_compute_subnetwork', $decoded['resource']);
    }
}
