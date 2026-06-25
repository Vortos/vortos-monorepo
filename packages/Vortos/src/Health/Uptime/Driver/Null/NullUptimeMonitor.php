<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\Null;

use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;

/**
 * In-core, test/offline default. Declares no real capabilities and always reports
 * {@see \Vortos\Health\Uptime\MonitorState::Unknown} — an explicit, honest "no
 * external detector configured", never a silent fake-Up.
 */
#[AsDriver('null')]
final class NullUptimeMonitor implements UptimeMonitorInterface
{
    public function sync(MonitorDescriptor $descriptor): string
    {
        $this->assertSupported($descriptor);

        return 'null-' . $descriptor->key;
    }

    public function status(string $monitorId): MonitorStatus
    {
        return MonitorStatus::unknown($monitorId);
    }

    public function statuses(array $monitorIds): array
    {
        return array_map($this->status(...), $monitorIds);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            UptimeCapability::SyntheticJourney->value => false,
            UptimeCapability::MultiRegion->value => false,
            UptimeCapability::IncidentApi->value => false,
            UptimeCapability::ResponseTimeSlo->value => false,
        ]);
    }

    private function assertSupported(MonitorDescriptor $descriptor): void
    {
        if ($descriptor->regions !== [] && !$this->capabilities()->supports(UptimeCapability::MultiRegion)) {
            throw UnsupportedCapabilityException::for('null', UptimeCapability::MultiRegion);
        }
        if ($descriptor->responseTimeSloMs !== null && !$this->capabilities()->supports(UptimeCapability::ResponseTimeSlo)) {
            throw UnsupportedCapabilityException::for('null', UptimeCapability::ResponseTimeSlo);
        }
    }
}
