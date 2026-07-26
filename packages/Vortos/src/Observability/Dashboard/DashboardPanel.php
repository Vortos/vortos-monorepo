<?php

declare(strict_types=1);

namespace Vortos\Observability\Dashboard;

/**
 * One Grafana panel, expressed in framework terms rather than raw Grafana JSON.
 *
 * Keeping panels as value objects means the dashboard is generated from the same
 * {@see \Vortos\Observability\Telemetry\FrameworkMetric} vocabulary the code emits, so a renamed
 * metric breaks generation instead of silently producing a dashboard of empty graphs.
 */
final readonly class DashboardPanel
{
    public function __construct(
        public string $title,
        public string $query,
        public DashboardPanelType $type = DashboardPanelType::TimeSeries,
        public string $unit = 'short',
        public string $description = '',
        public ?float $warnThreshold = null,
        public ?float $criticalThreshold = null,
        public string $legend = '',
    ) {}
}
