<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\MonitorTickReport;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;

final class MonitorTickReportTest extends TestCase
{
    public function testHealthyWhenNoIssues(): void
    {
        $report = new MonitorTickReport(
            [new MonitorStatus('m1', MonitorState::Up, 1.0, new DateTimeImmutable())],
            [ProbeResult::pass('disk', ProbeKind::Readiness, 1.0)],
            [],
            null,
            null,
        );

        self::assertTrue($report->isHealthy());
    }

    public function testUnhealthyWhenAProbeFails(): void
    {
        $report = new MonitorTickReport(
            [],
            [ProbeResult::fail('disk', ProbeKind::Readiness, 1.0, 'capacity_critical')],
            [],
            null,
            null,
        );

        self::assertFalse($report->isHealthy());
    }

    public function testWarnProbeDoesNotMakeReportUnhealthy(): void
    {
        $report = new MonitorTickReport(
            [],
            [ProbeResult::warn('disk', ProbeKind::Readiness, 1.0, 'capacity_warn')],
            [],
            null,
            null,
        );

        self::assertTrue($report->isHealthy());
    }

    public function testUnhealthyWhenMonitorIsDown(): void
    {
        $report = new MonitorTickReport(
            [new MonitorStatus('m1', MonitorState::Down, null, new DateTimeImmutable())],
            [],
            [],
            null,
            null,
        );

        self::assertFalse($report->isHealthy());
    }

    public function testUnhealthyWhenAProbeErrored(): void
    {
        $report = new MonitorTickReport([], [], ['disk' => 'boom'], null, null);

        self::assertFalse($report->isHealthy());
    }
}
