<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime\Driver\BetterStack;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Tests\Fixtures\InMemoryBetterStackTransport;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackClient;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackJourneyRenderer;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackUptimeMonitor;
use Vortos\Health\Uptime\MonitorState;

final class BetterStackUptimeMonitorTest extends TestCase
{
    private const TOKEN_ENV_VAR = 'TEST_BSU_TOKEN';

    protected function setUp(): void
    {
        $_ENV[self::TOKEN_ENV_VAR] = 'tok';
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::TOKEN_ENV_VAR]);
    }

    private function monitorWithBody(string $body, int $status = 200): BetterStackUptimeMonitor
    {
        $transport = (new InMemoryBetterStackTransport())->withResponse(['status' => $status, 'body' => $body]);
        $client = new BetterStackClient($transport, self::TOKEN_ENV_VAR);

        return new BetterStackUptimeMonitor($client, new BetterStackJourneyRenderer());
    }

    public function testTransportFailureDegradesToUnknownNeverThrows(): void
    {
        $monitor = $this->monitorWithBody('garbage', 503);

        $status = $monitor->status('mon-1');

        self::assertSame(MonitorState::Unknown, $status->state);
    }

    public function testMapsUpStatus(): void
    {
        $monitor = $this->monitorWithBody(json_encode(['data' => ['attributes' => ['status' => 'up', 'response_time' => 42.5]]]));

        $status = $monitor->status('mon-1');

        self::assertSame(MonitorState::Up, $status->state);
        self::assertSame(42.5, $status->latencyMs);
    }

    public function testMapsDownStatusWithIncidentId(): void
    {
        $monitor = $this->monitorWithBody(json_encode([
            'data' => ['attributes' => ['status' => 'down', 'last_incident_id' => 'inc-1']],
        ]));

        $status = $monitor->status('mon-1');

        self::assertSame(MonitorState::Down, $status->state);
        self::assertSame('inc-1', $status->incidentId);
    }

    public function testMapsDegradedStatusWithFailingRegions(): void
    {
        $monitor = $this->monitorWithBody(json_encode([
            'data' => ['attributes' => ['status' => 'degraded', 'failing_regions' => ['eu-west', 'us-east']]],
        ]));

        $status = $monitor->status('mon-1');

        self::assertSame(MonitorState::Degraded, $status->state);
        self::assertSame(['eu-west', 'us-east'], $status->failingRegions);
    }

    public function testValidatingStatusMapsToDegraded(): void
    {
        $monitor = $this->monitorWithBody(json_encode(['data' => ['attributes' => ['status' => 'validating']]]));

        self::assertSame(MonitorState::Degraded, $monitor->status('mon-1')->state);
    }

    public function testUnrecognizedStatusValueDegradesToUnknown(): void
    {
        $monitor = $this->monitorWithBody(json_encode(['data' => ['attributes' => ['status' => 'something-new']]]));

        self::assertSame(MonitorState::Unknown, $monitor->status('mon-1')->state);
    }

    public function testMissingAttributesDegradesToUnknown(): void
    {
        $monitor = $this->monitorWithBody(json_encode(['data' => []]));

        self::assertSame(MonitorState::Unknown, $monitor->status('mon-1')->state);
    }

    public function testStatusesBulkReadMapsEachId(): void
    {
        $monitor = $this->monitorWithBody(json_encode(['data' => ['attributes' => ['status' => 'up']]]));

        $statuses = $monitor->statuses(['mon-1', 'mon-2']);

        self::assertCount(2, $statuses);
        self::assertSame('mon-1', $statuses[0]->monitorId);
        self::assertSame('mon-2', $statuses[1]->monitorId);
    }
}
