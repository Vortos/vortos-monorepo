<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;
use Vortos\Observability\Telemetry\MetricOperation;

/**
 * @internal Used only by PersistenceMetricsDriver
 */
final class PersistenceMetricsConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $wrappedConnection,
        private readonly FrameworkTelemetry $telemetry,
    ) {
        parent::__construct($wrappedConnection);
    }

    public function query(string $sql): Result
    {
        $start = hrtime(true);
        try {
            return parent::query($sql);
        } finally {
            $this->record('query', $start);
        }
    }

    public function exec(string $sql): int
    {
        $start = hrtime(true);
        try {
            return parent::exec($sql);
        } finally {
            $this->record('execute', $start);
        }
    }

    public function prepare(string $sql): Statement
    {
        return new PersistenceMetricsStatement(parent::prepare($sql), $this->telemetry);
    }

    private function record(string $operation, int $start): void
    {
        $durationMs = (hrtime(true) - $start) / 1_000_000;

        $labels = FrameworkMetricLabels::of(
            MetricLabelValue::of(MetricLabel::Driver, 'dbal'),
            MetricLabelValue::operation($operation === 'query' ? MetricOperation::Query : MetricOperation::Execute),
        );
        $durationLabels = FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Driver, 'dbal'));

        $this->telemetry->increment(ObservabilityModule::Persistence, FrameworkMetric::DbQueriesTotal, $labels);
        $this->telemetry->observe(ObservabilityModule::Persistence, FrameworkMetric::DbQueryDurationMs, $durationLabels, $durationMs);
    }
}
