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
}
