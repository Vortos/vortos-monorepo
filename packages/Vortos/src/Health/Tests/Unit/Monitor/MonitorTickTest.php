<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\HeartbeatPolicy;
use Vortos\Health\Monitor\MonitorTick;
use Vortos\Health\Tests\Fixtures\FakeHeartbeatEmitter;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Tests\Fixtures\StubProbe;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Observability\Heartbeat\HeartbeatStatus;

final class MonitorTickTest extends TestCase
{
    public function testFiresHeartbeatSuccessWhenAllProbesHealthy(): void
    {
        $emitter = new FakeHeartbeatEmitter();
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy(), $emitter);

        $report = $tick->run([], [StubProbe::readiness('disk')]);

        self::assertTrue($report->isHealthy());
        self::assertCount(2, $emitter->received());
        self::assertSame(HeartbeatStatus::Start, $emitter->received()[0]->status);
        self::assertSame(HeartbeatStatus::Success, $emitter->received()[1]->status);
    }

    public function testFiresHeartbeatFailWhenACriticalProbeFails(): void
    {
        $emitter = new FakeHeartbeatEmitter();
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy(), $emitter);

        $failingProbe = StubProbe::readiness('cert-expiry')
            ->withResult(ProbeResult::fail('cert-expiry', ProbeKind::Readiness, 1.0, 'cert_near_expiry_critical'));

        $report = $tick->run([], [$failingProbe]);

        self::assertFalse($report->isHealthy());
        self::assertSame(HeartbeatStatus::Fail, $emitter->received()[1]->status);
    }

    public function testRunsWithoutHeartbeatEmitterWhenObservabilityAbsent(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());

        $report = $tick->run([], [StubProbe::readiness('disk')]);

        self::assertNull($report->heartbeatAcknowledged);
        self::assertTrue($report->isHealthy());
    }

    public function testOneProbeThrowingDoesNotAbortTheTick(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());

        $throwing = StubProbe::readiness('broken')->withException(new \RuntimeException('boom'));
        $healthy = StubProbe::readiness('disk');

        $report = $tick->run([], [$throwing, $healthy]);

        self::assertCount(1, $report->probeResults);
        self::assertSame('disk', $report->probeResults[0]->name);
        self::assertArrayHasKey('broken', $report->probeErrors);
        self::assertSame('boom', $report->probeErrors['broken']);
        self::assertFalse($report->isHealthy());
    }

    public function testPollsUptimeMonitorStatuses(): void
    {
        $monitor = (new FakeUptimeMonitor())
            ->withStatus('m1', new MonitorStatus('m1', MonitorState::Up, 12.0, new DateTimeImmutable()));

        $tick = new MonitorTick($monitor, new HeartbeatPolicy());

        $report = $tick->run(['m1'], []);

        self::assertCount(1, $report->monitorStatuses);
        self::assertSame(MonitorState::Up, $report->monitorStatuses[0]->state);
    }

    public function testDownMonitorMakesReportUnhealthy(): void
    {
        $monitor = (new FakeUptimeMonitor())
            ->withStatus('m1', new MonitorStatus('m1', MonitorState::Down, null, new DateTimeImmutable()));

        $tick = new MonitorTick($monitor, new HeartbeatPolicy());

        $report = $tick->run(['m1'], []);

        self::assertFalse($report->isHealthy());
    }

    public function testUnknownMonitorStatusDoesNotMakeReportUnhealthy(): void
    {
        $monitor = new FakeUptimeMonitor();

        $tick = new MonitorTick($monitor, new HeartbeatPolicy());

        $report = $tick->run(['never-synced'], []);

        self::assertSame(MonitorState::Unknown, $report->monitorStatuses[0]->state);
        self::assertTrue($report->isHealthy());
    }
}
