<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
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
 * @internal Used only by PersistenceMetricsConnection
 */
final class PersistenceMetricsStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $wrappedStatement,
        private readonly FrameworkTelemetry $telemetry,
    ) {
        parent::__construct($wrappedStatement);
    }

    public function execute(): Result
    {
        $start  = hrtime(true);
        try {
            return parent::execute();
        } finally {
            $durationMs = (hrtime(true) - $start) / 1_000_000;
            $this->telemetry->increment(
                ObservabilityModule::Persistence,
                FrameworkMetric::DbQueriesTotal,
                FrameworkMetricLabels::of(
                    MetricLabelValue::of(MetricLabel::Driver, 'dbal'),
                    MetricLabelValue::operation(MetricOperation::Execute),
                ),
            );
            $this->telemetry->observe(
                ObservabilityModule::Persistence,
                FrameworkMetric::DbQueryDurationMs,
                FrameworkMetricLabels::of(MetricLabelValue::of(MetricLabel::Driver, 'dbal')),
                $durationMs,
            );
        }
    }
}
