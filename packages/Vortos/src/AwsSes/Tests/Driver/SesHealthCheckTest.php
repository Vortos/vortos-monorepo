<?php

declare(strict_types=1);

namespace Vortos\AwsSes\Tests\Driver;

use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use PHPUnit\Framework\TestCase;
use Vortos\AwsSes\Driver\Ses\Health\SesHealthCheck;

final class SesHealthCheckTest extends TestCase
{
    private function makeCheck(MockHandler $handler): SesHealthCheck
    {
        $client = new SesV2Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler'     => $handler,
        ]);

        return new SesHealthCheck($client);
    }

    public function test_name_is_ses(): void
    {
        $handler = new MockHandler();
        $check   = $this->makeCheck($handler);
        $this->assertSame('ses', $check->name());
    }

    public function test_healthy_when_account_reachable_and_production(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SendingEnabled'          => true,
            'ProductionAccessEnabled' => true,
            'SendQuota'               => [
                'Max24HourSend'    => 50000.0,
                'SentLast24Hours'  => 1000.0,
                'MaxSendRate'      => 14.0,
            ],
        ]));

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy);
        $this->assertNull($result->error);
    }

    public function test_degraded_when_in_sandbox(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SendingEnabled'          => true,
            'ProductionAccessEnabled' => false,
            'SendQuota'               => [
                'Max24HourSend'   => 200.0,
                'SentLast24Hours' => 0.0,
                'MaxSendRate'     => 1.0,
            ],
        ]));

        $result = $this->makeCheck($handler)->check();

        $this->assertTrue($result->healthy);
        $this->assertSame('ses_sandbox_mode', $result->errorCode);
        $this->assertFalse($result->critical);
    }

    public function test_unhealthy_when_sending_disabled(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SendingEnabled'          => false,
            'ProductionAccessEnabled' => true,
            'SendQuota'               => [],
        ]));

        $result = $this->makeCheck($handler)->check();

        $this->assertFalse($result->healthy);
        $this->assertSame('ses_sending_disabled', $result->errorCode);
    }

    public function test_unhealthy_when_quota_over_90_percent(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SendingEnabled'          => true,
            'ProductionAccessEnabled' => true,
            'SendQuota'               => [
                'Max24HourSend'   => 50000.0,
                'SentLast24Hours' => 48000.0,  // 96% used
                'MaxSendRate'     => 14.0,
            ],
        ]));

        $result = $this->makeCheck($handler)->check();

        $this->assertFalse($result->healthy);
        $this->assertSame('ses_quota_critical', $result->errorCode);
    }

    public function test_unhealthy_when_api_throws(): void
    {
        $handler = new MockHandler();
        $handler->append(new AwsException(
            'Connection refused',
            new \Aws\Command('GetAccount'),
            ['code' => 'NetworkingError', 'message' => 'Connection refused'],
        ));

        $result = $this->makeCheck($handler)->check();

        $this->assertFalse($result->healthy);
        $this->assertSame('ses_unreachable', $result->errorCode);
    }

    public function test_latency_ms_is_positive(): void
    {
        $handler = new MockHandler();
        $handler->append(new Result([
            'SendingEnabled'          => true,
            'ProductionAccessEnabled' => true,
            'SendQuota'               => ['Max24HourSend' => 1000.0, 'SentLast24Hours' => 0.0],
        ]));

        $result = $this->makeCheck($handler)->check();

        $this->assertGreaterThanOrEqual(0, $result->latencyMs);
    }
}
