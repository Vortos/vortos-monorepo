<?php

declare(strict_types=1);

namespace Vortos\Health\Testing;

use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\SyntheticJourney;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The TCK every {@see UptimeMonitorInterface} driver must pass: sync idempotency,
 * bounded/shape-correct status reads, and the negative capability-honesty case
 * (asking for multi-region/SLO a driver does not declare must raise
 * UnsupportedCapabilityException, never silently no-op).
 */
abstract class UptimeMonitorConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createMonitor(): UptimeMonitorInterface;

    protected function createDriver(): UptimeMonitorInterface
    {
        return $this->createMonitor();
    }

    final public function testSyncIsIdempotent(): void
    {
        $monitor = $this->createMonitor();
        $descriptor = $this->basicDescriptor();

        $first = $monitor->sync($descriptor);
        $second = $monitor->sync($descriptor);

        self::assertSame($first, $second, 'sync() must be idempotent for an unchanged descriptor.');
    }

    final public function testStatusReturnsMonitorStatusForTheSyncedId(): void
    {
        $monitor = $this->createMonitor();
        $id = $monitor->sync($this->basicDescriptor());

        $status = $monitor->status($id);

        self::assertInstanceOf(MonitorStatus::class, $status);
        self::assertSame($id, $status->monitorId);
    }

    final public function testStatusesBulkReadMatchesInputShape(): void
    {
        $monitor = $this->createMonitor();
        $id = $monitor->sync($this->basicDescriptor());

        $statuses = $monitor->statuses([$id, $id]);

        self::assertCount(2, $statuses);
        foreach ($statuses as $status) {
            self::assertInstanceOf(MonitorStatus::class, $status);
            self::assertSame($id, $status->monitorId);
        }
    }

    final public function testStatusesBulkReadOfEmptyListIsEmpty(): void
    {
        self::assertSame([], $this->createMonitor()->statuses([]));
    }

    final public function testUndeclaredMultiRegionCapabilityIsRejectedHonestly(): void
    {
        $monitor = $this->createMonitor();
        if ($monitor->capabilities()->supports(UptimeCapability::MultiRegion)) {
            self::markTestSkipped('Driver supports multi-region; negative case not applicable.');
        }

        $this->assertRejectsUnsupportedCapability(
            fn () => $monitor->sync($this->basicDescriptor(regions: ['eu-west', 'us-east'])),
        );
    }

    final public function testUndeclaredResponseTimeSloCapabilityIsRejectedHonestly(): void
    {
        $monitor = $this->createMonitor();
        if ($monitor->capabilities()->supports(UptimeCapability::ResponseTimeSlo)) {
            self::markTestSkipped('Driver supports response-time SLO; negative case not applicable.');
        }

        $this->assertRejectsUnsupportedCapability(
            fn () => $monitor->sync($this->basicDescriptor(responseTimeSloMs: 500)),
        );
    }

    /** @param list<string> $regions */
    private function basicDescriptor(array $regions = [], ?int $responseTimeSloMs = null): MonitorDescriptor
    {
        return new MonitorDescriptor(
            key: 'tck-test-monitor',
            name: 'TCK Test Monitor',
            journey: new SyntheticJourney('login-fetch', [
                new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
                new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
            ]),
            regions: $regions,
            responseTimeSloMs: $responseTimeSloMs,
        );
    }
}
