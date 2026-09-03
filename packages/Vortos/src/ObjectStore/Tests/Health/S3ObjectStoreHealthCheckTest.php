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
    public function test_reports_healthy_when_object_head_succeeds(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([]));

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy);
        $this->assertSame('object_store', $result->name);
    }

    /**
     * The regression this whole change exists for. A signed HeadObject that comes back 404 has
     * proved the bucket is reachable and the object-scoped credential is accepted; the sentinel is
     * absent by design. Treating that as an outage is the false positive that pinned a permanent
     * critical alert on a perfectly healthy store — so a 404 must read as HEALTHY, on the first try,
     * with no retry.
     */
    public function test_reports_healthy_when_the_sentinel_object_is_absent(): void
    {
        $handler = new MockHandler();
        $handler->append($this->awsNotFound());

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy, 'A 404 on the sentinel key proves reachability, not unreachability.');
    }

    public function test_reports_unhealthy_on_access_denied(): void
    {
        $handler = new MockHandler();
        // 403 AccessDenied on every attempt → a genuine credentials/permission problem, unhealthy.
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
            new \Aws\Command('HeadObject'),
            [
                'code' => 'AccessDenied',
                'message' => 'Access denied',
                'response' => new \GuzzleHttp\Psr7\Response(403),
            ],
        );
    }

    private function awsNotFound(): AwsException
    {
        return new AwsException(
            'Not Found',
            new \Aws\Command('HeadObject'),
            [
                'code' => 'NotFound',
                'message' => 'Not Found',
                'response' => new \GuzzleHttp\Psr7\Response(404),
            ],
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
