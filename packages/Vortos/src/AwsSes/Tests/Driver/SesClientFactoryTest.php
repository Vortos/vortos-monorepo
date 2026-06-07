<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Driver;

use Aws\SesV2\SesV2Client;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Driver\Ses\SesClientFactory;

final class SesClientFactoryTest extends TestCase
{
    public function test_creates_ses_v2_client(): void
    {
        $client = SesClientFactory::create('us-east-1', null, 2.0, 3);
        $this->assertInstanceOf(SesV2Client::class, $client);
    }

    public function test_creates_client_for_different_regions(): void
    {
        $client = SesClientFactory::create('eu-west-1', null, 2.0, 3);
        $this->assertInstanceOf(SesV2Client::class, $client);
    }

    public function test_creates_client_with_endpoint_override(): void
    {
        // Endpoint override is used for LocalStack — just verify the client is created
        $client = SesClientFactory::create('us-east-1', 'http://localstack:4566', 2.0, 3);
        $this->assertInstanceOf(SesV2Client::class, $client);
    }

    public function test_creates_client_with_custom_timeout(): void
    {
        $client = SesClientFactory::create('us-east-1', null, 5.0, 5);
        $this->assertInstanceOf(SesV2Client::class, $client);
    }
}
