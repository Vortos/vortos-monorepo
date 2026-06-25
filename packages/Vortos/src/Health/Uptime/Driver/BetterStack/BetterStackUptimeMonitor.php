<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime\Driver\BetterStack;

use DateTimeImmutable;
use Throwable;
use Vortos\Health\Uptime\Capability\UptimeCapability;
use Vortos\Health\Uptime\MonitorDescriptor;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * No heavy SDK dependency (plain curl, §14.2 "no new dependency ⇒ in-core"), so this
 * lives in-core behind the `betterstack` key rather than a split package. `status()`
 * is bounded and never throws — any client failure degrades to
 * {@see MonitorState::Unknown}, never a false page.
 */
#[AsDriver('betterstack')]
final class BetterStackUptimeMonitor implements UptimeMonitorInterface
{
    public function __construct(
        private readonly BetterStackClient $client,
        private readonly BetterStackJourneyRenderer $renderer,
    ) {}

    public function sync(MonitorDescriptor $descriptor): string
    {
        return $this->client->createOrUpdateMonitor($descriptor->key, $this->renderer->render($descriptor));
    }

    public function status(string $monitorId): MonitorStatus
    {
        try {
            $raw = $this->client->monitorStatus($monitorId);
        } catch (Throwable) {
            return MonitorStatus::unknown($monitorId);
        }

        return $this->mapStatus($monitorId, $raw);
    }

    public function statuses(array $monitorIds): array
    {
        return array_map($this->status(...), $monitorIds);
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

    /** @param array<string, mixed> $raw */
    private function mapStatus(string $monitorId, array $raw): MonitorStatus
    {
        $attrs = $raw['data']['attributes'] ?? null;
        if (!is_array($attrs)) {
            return MonitorStatus::unknown($monitorId);
        }

        $state = match ($attrs['status'] ?? null) {
            'up' => MonitorState::Up,
            'down' => MonitorState::Down,
            'degraded', 'validating' => MonitorState::Degraded,
            default => MonitorState::Unknown,
        };

        if ($state === MonitorState::Unknown) {
            return MonitorStatus::unknown($monitorId);
        }

        $latency = is_numeric($attrs['response_time'] ?? null) ? (float) $attrs['response_time'] : null;

        $failingRegions = [];
        if (is_array($attrs['failing_regions'] ?? null)) {
            foreach ($attrs['failing_regions'] as $region) {
                if (is_string($region) && $region !== '') {
                    $failingRegions[] = $region;
                }
            }
        }

        $incidentId = is_string($attrs['last_incident_id'] ?? null) && $attrs['last_incident_id'] !== ''
            ? $attrs['last_incident_id']
            : null;

        return new MonitorStatus($monitorId, $state, $latency, new DateTimeImmutable(), $failingRegions, $incidentId);
    }
}
