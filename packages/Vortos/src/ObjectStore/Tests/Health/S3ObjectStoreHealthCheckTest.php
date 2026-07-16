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
        // All three attempts fail → unhealthy.
        for ($i = 0; $i < 3; $i++) {
            $handler->append($this->awsFailure());
        }

        $result = $this->makeCheck($handler)->check();

        $this->assertFalse($result->healthy);
        $this->assertSame('object_store_unreachable', $result->errorCode);
    }

    public function test_absorbs_a_cold_start_blip_and_recovers_on_retry(): void
    {
        $handler = new MockHandler();
        // First attempt fails (cold connection), second succeeds — a healthy store must not
        // false-negative the readiness gate just because the first probe was cold.
        $handler->append($this->awsFailure());
        $handler->append(new Result([]));

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy);
    }

    public function test_single_attempt_disables_cold_start_retry(): void
    {
        $handler = new MockHandler();
        $handler->append($this->awsFailure());

        // attempts=1 → no retry; a single failure reports unhealthy immediately.
        $result = $this->makeCheck($handler, attempts: 1)->check();

        $this->assertFalse($result->healthy);
    }

    private function awsFailure(): AwsException
    {
        return new AwsException(
            'Access denied',
            new \Aws\Command('HeadBucket'),
            ['code' => 'AccessDenied', 'message' => 'Access denied'],
        );
    }

    private function makeCheck(MockHandler $handler, int $attempts = 3): S3ObjectStoreHealthCheck
    {
        $client = new S3Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => $handler,
        ]);

        // backoff 0 keeps the test instant; the retry logic is independent of the delay.
        return new S3ObjectStoreHealthCheck($client, 'media', 'r2', coldStartAttempts: $attempts, coldStartBackoffMs: 0);
    }
}
