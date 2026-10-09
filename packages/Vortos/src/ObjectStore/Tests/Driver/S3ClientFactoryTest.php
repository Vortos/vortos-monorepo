<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Driver\S3\S3ClientFactory;
use Vortos\ObjectStore\Exception\ObjectStoreConfigurationException;

final class S3ClientFactoryTest extends TestCase
{
    public function test_r2_endpoint_is_derived_from_account_id(): void
    {
        $client = S3ClientFactory::create(
            provider: 'r2',
            region: 'auto',
            endpoint: null,
            accountId: 'abc123',
            accessKeyId: 'key',
            secretAccessKey: 'secret',
            httpTimeout: 10.0,
            connectTimeout: 2.0,
            maxRetries: 3,
            pathStyleEndpoint: false,
        );

        $this->assertSame('https://abc123.r2.cloudflarestorage.com', (string) $client->getEndpoint());
        $this->assertSame('auto', $client->getRegion());
    }

    public function test_explicit_endpoint_wins_for_r2(): void
    {
        $client = S3ClientFactory::create(
            provider: 'r2',
            region: 'auto',
            endpoint: 'https://custom.example.test',
            accountId: null,
            accessKeyId: 'key',
            secretAccessKey: 'secret',
            httpTimeout: 10.0,
            connectTimeout: 2.0,
            maxRetries: 3,
            pathStyleEndpoint: true,
        );

        $this->assertSame('https://custom.example.test', (string) $client->getEndpoint());
        $this->assertTrue($client->getConfig('use_path_style_endpoint'));
    }

    public function test_r2_requires_endpoint_or_account_id(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);

        S3ClientFactory::create(
            provider: 'r2',
            region: 'auto',
            endpoint: null,
            accountId: null,
            accessKeyId: 'key',
            secretAccessKey: 'secret',
            httpTimeout: 10.0,
            connectTimeout: 2.0,
            maxRetries: 3,
            pathStyleEndpoint: false,
        );
    }

    public function test_bulk_transfer_client_has_no_total_timeout_and_fails_on_a_stall(): void
    {
        $client = S3ClientFactory::createForBulkTransfer(
            provider: 'r2',
            region: 'auto',
            endpoint: null,
            accountId: 'abc123',
            accessKeyId: 'key',
            secretAccessKey: 'secret',
            connectTimeout: 5.0,
            stallTimeoutSeconds: 60,
            stallMinBytesPerSecond: 1024,
            maxRetries: 5,
            pathStyleEndpoint: false,
        );

        // The SDK does not keep `http` as client config; it stamps it onto every command.
        $http = $client->getCommand('GetObject', ['Bucket' => 'b', 'Key' => 'k'])['@http'];
        $this->assertSame(0, $http['timeout'], 'a total timeout caps object size');
        $this->assertSame(5.0, $http['connect_timeout']);
        $this->assertSame(60.0, $http['read_timeout']);
        $this->assertSame(1024, $http['curl'][\CURLOPT_LOW_SPEED_LIMIT]);
        $this->assertSame(60, $http['curl'][\CURLOPT_LOW_SPEED_TIME]);
    }

    public function test_bulk_transfer_client_refuses_a_disabled_stall_rule(): void
    {
        $this->expectException(ObjectStoreConfigurationException::class);

        S3ClientFactory::createForBulkTransfer('r2', 'auto', null, 'abc123', 'key', 'secret', 5.0, 0, 1024, 5, false);
    }
}
