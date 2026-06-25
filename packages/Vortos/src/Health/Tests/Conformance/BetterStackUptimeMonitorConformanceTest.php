<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Testing\UptimeMonitorConformanceTestCase;
use Vortos\Health\Tests\Fixtures\InMemoryBetterStackTransport;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackClient;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackJourneyRenderer;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackUptimeMonitor;
use Vortos\Health\Uptime\UptimeMonitorInterface;

final class BetterStackUptimeMonitorConformanceTest extends UptimeMonitorConformanceTestCase
{
    private const TOKEN_ENV_VAR = 'TEST_BSU_TCK_TOKEN';

    protected function setUp(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'tok';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TOKEN_ENV_VAR]);
    }

    protected function createMonitor(): UptimeMonitorInterface
    {
        $transport = (new InMemoryBetterStackTransport())->withResponse([
            'status' => 200,
            'body' => json_encode([
                'data' => ['id' => 'mon-tck', 'attributes' => ['status' => 'up', 'response_time' => 10.0]],
            ]),
        ]);

        return new BetterStackUptimeMonitor(new BetterStackClient($transport, self::TOKEN_ENV_VAR), new BetterStackJourneyRenderer());
    }

    protected function expectedKey(): string
    {
        return 'betterstack';
    }
}
