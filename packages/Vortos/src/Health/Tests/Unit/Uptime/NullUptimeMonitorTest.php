<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\Driver\Null\NullUptimeMonitor;
use Vortos\Health\Uptime\JourneyStep;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\SyntheticJourney;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

final class NullUptimeMonitorTest extends TestCase
{
    private function descriptor(array $regions = [], ?int $sloMs = null): MonitorDescriptor
    {
        return new MonitorDescriptor(
            'key',
            'name',
            new SyntheticJourney('login-fetch', [
                new JourneyStep('POST', '/login', 200, extractAs: 'token', extractJsonPath: 'data.token'),
                new JourneyStep('GET', '/me', 200, bodyContains: '"email"'),
            ]),
            regions: $regions,
            responseTimeSloMs: $sloMs,
        );
    }

    public function testStatusIsAlwaysUnknown(): void
    {
        $monitor = new NullUptimeMonitor();

        self::assertSame(MonitorState::Unknown, $monitor->status('anything')->state);
    }

    public function testDeclaresNoCapabilities(): void
    {
        $caps = (new NullUptimeMonitor())->capabilities();

        self::assertFalse($caps->supports(\Vortos\Health\Uptime\Capability\UptimeCapability::SyntheticJourney));
        self::assertFalse($caps->supports(\Vortos\Health\Uptime\Capability\UptimeCapability::MultiRegion));
        self::assertFalse($caps->supports(\Vortos\Health\Uptime\Capability\UptimeCapability::IncidentApi));
        self::assertFalse($caps->supports(\Vortos\Health\Uptime\Capability\UptimeCapability::ResponseTimeSlo));
    }

    public function testSyncRejectsMultiRegionRequest(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);

        (new NullUptimeMonitor())->sync($this->descriptor(regions: ['eu-west']));
    }

    public function testSyncRejectsResponseTimeSloRequest(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);

        (new NullUptimeMonitor())->sync($this->descriptor(sloMs: 500));
    }

    public function testSyncWithoutExtraCapabilitiesSucceeds(): void
    {
        $id = (new NullUptimeMonitor())->sync($this->descriptor());

        self::assertSame('null-key', $id);
    }
}
