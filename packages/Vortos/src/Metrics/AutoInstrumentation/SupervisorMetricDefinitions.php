<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

/**
 * Definitions for the supervisord program metrics emitted by vortos-deploy's
 * {@see \Vortos\Deploy\Runtime\SupervisorMetricsReporter}.
 *
 * They live here, not in vortos-deploy, for the same reason MessagingMetricDefinitions does: this
 * package owns every framework metric's declared shape, and an undeclared metric throws
 * MetricNotDefinedException at the call site.
 */
final class SupervisorMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::gauge(
                'supervisor_program_up',
                '1 when a supervisord program is RUNNING, 0 in any other state.',
                ['program'],
            ),
            MetricDefinition::gauge(
                'supervisor_program_uptime_seconds',
                'Seconds since the current process for this supervisord program started.',
                ['program'],
            ),
            MetricDefinition::counter(
                'supervisor_program_restarts_total',
                'Times supervisord has respawned a program — the signal a crash-loop hides behind a healthy-looking RUNNING state.',
                ['program'],
            ),
            MetricDefinition::gauge(
                'supervisor_program_memory_bytes',
                'Resident set size of the current process for this supervisord program.',
                ['program'],
            ),
        ];
    }
}
