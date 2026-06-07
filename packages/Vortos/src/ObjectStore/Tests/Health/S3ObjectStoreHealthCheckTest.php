<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Tests\Health;

use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;
use Vortos\ObjectStore\Health\S3ObjectStoreHealthCheck;

final class S3ObjectStoreHealthCheckTest extends TestCase
{
    public function test_reports_healthy_when_bucket_is_reachable(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([]));

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy);
        $this->assertSame('object_store', $result->name);
    }

    public function test_reports_unhealthy_when_head_bucket_fails(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Access denied',
            new \Aws\Command('HeadBucket'),
            ['code' => 'AccessDenied', 'message' => 'Access denied'],
        ));

        $result = $this->makeCheck($handler)->check();

        $this->assertFalse($result->healthy);
        $this->assertSame('object_store_unreachable', $result->errorCode);
    }

    private function makeCheck(MockHandler $handler): S3ObjectStoreHealthCheck
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);

        return new S3ObjectStoreHealthCheck($client, 'media', 'r2');
    }
}
