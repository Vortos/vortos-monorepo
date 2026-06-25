<?php

declare(strict_types=1);

namespace Vortos\Observability\Sink;

use Vortos\OpsKit\Driver\DriverInterface;

/**
 * A telemetry destination for metrics/traces/logs — the config-only swap point of
 * the §12.4 design.
 *
 * The driver is deliberately **thin**: it does not transport telemetry itself (the
 * app emits OTLP to a loopback collector, the collector forwards). It instead (a)
 * declares its capabilities for config-time validation and (b) renders the collector
 * **exporter fragment** for its backend via {@see exporterConfig()}. Switching
 * `grafana` → `datadog` therefore changes only which driver is selected — the
 * generated collector exporter changes, app code never does.
 */
interface MetricsSinkInterface extends DriverInterface
{
    /** Stable lower-kebab key; equals the driver's #[AsDriver] key. */
    public function name(): string;

    /**
     * Which signal families this sink carries.
     *
     * @return list<TelemetrySignal>
     */
    public function signals(): array;

    /** The off-host endpoint the collector exports to. */
    public function endpoint(): SinkEndpoint;

    /** The collector exporter fragment that forwards telemetry to this backend. */
    public function exporterConfig(): ExporterConfig;
}
