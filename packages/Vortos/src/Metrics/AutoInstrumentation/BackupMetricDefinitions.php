<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Vortos\Metrics\Definition\MetricDefinition;
use Vortos\Metrics\Definition\MetricDefinitionProviderInterface;

/**
 * Definitions for the backup freshness gauges emitted by vortos-backup's
 * {@see \Vortos\Backup\Observability\BackupFreshnessCollector}.
 *
 * Declared here, like every other framework metric, because an undeclared metric throws
 * MetricNotDefinedException at the call site.
 */
final class BackupMetricDefinitions implements MetricDefinitionProviderInterface
{
    public function definitions(): array
    {
        return [
            MetricDefinition::gauge(
                'backup_present',
                '1 when at least one backup exists for this engine and environment, 0 when none has ever been recorded.',
                ['engine', 'environment'],
            ),
            MetricDefinition::gauge(
                'backup_last_success_age_seconds',
                'Seconds since the newest recorded backup — the dead-man signal for a backup schedule that stopped running rather than started failing.',
                ['engine', 'environment'],
            ),
            MetricDefinition::gauge(
                'backup_last_success_size_bytes',
                'Size of the newest recorded backup; a sudden collapse means a backup that "succeeded" but captured nothing.',
                ['engine', 'environment'],
            ),
        ];
    }
}
