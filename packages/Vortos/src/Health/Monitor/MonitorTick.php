<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

use Throwable;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\Observability\Heartbeat\HeartbeatEmitterInterface;

/**
 * Pure orchestration over injected collaborators (§6.5) — emits the dead-man
 * heartbeat, polls the external monitor, and samples capacity/cert probes. The
 * heartbeat emitter is optional: when Observability is absent, the tick still runs
 * uptime + probe sampling, it just skips the push (§5 hand-off requirement). One
 * probe throwing never aborts the tick — every other probe still reports (§6.2
 * isolation).
 */
final class MonitorTick
{
    public function __construct(
        private readonly UptimeMonitorInterface $monitor,
        private readonly HeartbeatPolicy $heartbeatPolicy,
        private readonly ?HeartbeatEmitterInterface $heartbeatEmitter = null,
        private readonly string $heartbeatMonitorKey = 'health-monitor-tick',
    ) {}

    /**
     * @param list<string>              $monitorIds off-host monitor ids to poll
     * @param list<HealthProbeInterface> $probes     capacity/cert probes to sample this tick
     */
    public function run(array $monitorIds, array $probes): MonitorTickReport
    {
        $this->heartbeatEmitter?->emit($this->heartbeatPolicy->start($this->heartbeatMonitorKey));

        $statuses = $this->monitor->statuses($monitorIds);

        $results = [];
        $errors = [];

        foreach ($probes as $probe) {
            try {
                $results[] = $probe->check();
            } catch (Throwable $e) {
                $errors[$probe->name()] = $e->getMessage();
            }
        }

        $finishPing = $this->heartbeatPolicy->finish($this->heartbeatMonitorKey, $results);
        $finishAck = $this->heartbeatEmitter?->emit($finishPing);

        return new MonitorTickReport($statuses, $results, $errors, $finishPing, $finishAck);
    }
}
