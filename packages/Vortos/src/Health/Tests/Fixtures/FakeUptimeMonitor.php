<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Fixtures;

use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Full-capability in-memory test double — used both as the TCK's "fake driver" and
 * by MonitorTick/Alerts-integration tests to control exactly what status() returns
 * without a real provider.
 */
final class FakeUptimeMonitor implements UptimeMonitorInterface
{
    /** @var array<string, MonitorStatus> */
    private array $statuses = [];

    /** @var array<string, string> */
    private array $syncedIds = [];

    private int $syncCallCount = 0;

    public function sync(MonitorDescriptor $descriptor): string
    {
        $this->syncCallCount++;

        $id = $this->syncedIds[$descriptor->key] ?? ('fake-' . $descriptor->key);
        $this->syncedIds[$descriptor->key] = $id;

        if (!isset($this->statuses[$id])) {
            $this->statuses[$id] = MonitorStatus::unknown($id);
        }

        return $id;
    }

    public function withStatus(string $monitorId, MonitorStatus $status): self
    {
        $clone = clone $this;
        $clone->statuses[$monitorId] = $status;

        return $clone;
    }

    public function status(string $monitorId): MonitorStatus
    {
        return $this->statuses[$monitorId] ?? MonitorStatus::unknown($monitorId);
    }

    public function statuses(array $monitorIds): array
    {
        return array_map($this->status(...), $monitorIds);
    }

    public function syncCallCount(): int
    {
        return $this->syncCallCount;
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            UptimeCapability::SyntheticJourney->value => true,
            UptimeCapability::MultiRegion->value => true,
            UptimeCapability::IncidentApi->value => true,
            UptimeCapability::ResponseTimeSlo->value => true,
        ]);
    }
}
