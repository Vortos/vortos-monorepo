<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Observability\Heartbeat\HeartbeatPing;

/** Pure result of one {@see MonitorTick::run()} — for the CLI and for tests. */
final readonly class MonitorTickReport
{
    /**
     * @param list<MonitorStatus>  $monitorStatuses
     * @param list<ProbeResult>    $probeResults
     * @param array<string,string> $probeErrors keyed by probe name; a probe that threw
     *                                            during the tick (isolated, never abort)
     */
    public function __construct(
        public array $monitorStatuses,
        public array $probeResults,
        public array $probeErrors,
        public ?HeartbeatPing $heartbeatPing,
        public ?bool $heartbeatAcknowledged,
    ) {}

    public function isHealthy(): bool
    {
        foreach ($this->probeResults as $result) {
            if (!$result->isHealthy()) {
                return false;
            }
        }

        foreach ($this->monitorStatuses as $status) {
            if ($status->state === MonitorState::Down) {
                return false;
            }
        }

        return $this->probeErrors === [];
    }
}
