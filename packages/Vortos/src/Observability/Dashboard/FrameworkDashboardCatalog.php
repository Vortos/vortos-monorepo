<?php

declare(strict_types=1);

namespace Vortos\Observability\Dashboard;

use Vortos\Observability\Telemetry\FrameworkMetric;

/**
 * The panels every Vortos deployment wants, defined against {@see FrameworkMetric} rather than
 * hand-typed metric strings.
 *
 * A dashboard that lives only in Grafana Cloud is not reviewable, not versioned, and silently rots
 * when a metric is renamed — you find out during an incident, staring at an empty graph. Generating
 * it from the metric enum means a rename is a compile-time break here instead.
 *
 * The queries assume a Prometheus-compatible store (Grafana Cloud / Mimir), which is what the OTLP
 * pipeline writes into.
 */
final class FrameworkDashboardCatalog
{
    /**
     * @return list<DashboardSection>
     */
    public function sections(): array
    {
        return [
            $this->messagingSection(),
            $this->workerSection(),
            $this->backupSection(),
            $this->httpSection(),
        ];
    }

    private function messagingSection(): DashboardSection
    {
        return new DashboardSection('Messaging', [
            new DashboardPanel(
                title: 'Dead-letter backlog',
                query: sprintf('sum by (transport, event) (%s)', FrameworkMetric::DlqBacklogSize->value),
                type: DashboardPanelType::TimeSeries,
                description: 'Any value above zero is work a customer asked for that was dropped. There is no healthy steady state.',
                criticalThreshold: 1.0,
                legend: '{{transport}} / {{event}}',
            ),
            new DashboardPanel(
                title: 'Oldest stuck outbox message',
                query: sprintf('max by (transport) (%s)', FrameworkMetric::OutboxOldestPendingAgeSeconds->value),
                unit: 's',
                description: 'Depth alone hides a stalled relay: a queue of three that never drains looks identical to a healthy one. Age is what exposes it.',
                warnThreshold: 300.0,
                criticalThreshold: 900.0,
                legend: '{{transport}}',
            ),
            new DashboardPanel(
                title: 'Consumer lag',
                query: sprintf('sum by (consumer, topic) (%s)', FrameworkMetric::MessagingConsumerLag->value),
                description: 'Committed offset versus partition high watermark, sampled inside the consumer itself.',
                legend: '{{consumer}} / {{topic}}',
            ),
            new DashboardPanel(
                title: 'Consumer liveness (poll cycles/sec)',
                query: sprintf('sum by (consumer) (rate(%s[5m]))', FrameworkMetric::MessagingConsumerPollCyclesTotal->value),
                description: 'A flat line means the consumer process is gone or wedged — distinct from a consumer that is alive with nothing to do.',
                criticalThreshold: 0.0,
                legend: '{{consumer}}',
            ),
            new DashboardPanel(
                title: 'Consumers with no partitions assigned',
                query: sprintf('count(%s == 0)', FrameworkMetric::MessagingConsumerAssignedPartitions->value),
                type: DashboardPanelType::Stat,
                description: 'Subscribed but starved: mid-rebalance, or another group member holds every partition.',
            ),
        ]);
    }

    private function workerSection(): DashboardSection
    {
        return new DashboardSection('Workers', [
            new DashboardPanel(
                title: 'Programs not running',
                query: sprintf('count(%s == 0)', FrameworkMetric::SupervisorProgramUp->value),
                type: DashboardPanelType::Stat,
                description: 'Supervised programs in any state other than RUNNING.',
                criticalThreshold: 1.0,
            ),
            new DashboardPanel(
                title: 'Restart rate',
                query: sprintf('sum by (program) (rate(%s[15m]))', FrameworkMetric::SupervisorProgramRestartsTotal->value),
                description: 'A crash-looping program reports RUNNING in every individual sample; only the respawn rate reveals it.',
                legend: '{{program}}',
            ),
            new DashboardPanel(
                title: 'Worker memory',
                query: sprintf('sum by (program) (%s)', FrameworkMetric::SupervisorProgramMemoryBytes->value),
                unit: 'bytes',
                legend: '{{program}}',
            ),
        ]);
    }

    private function backupSection(): DashboardSection
    {
        return new DashboardSection('Backups', [
            new DashboardPanel(
                title: 'Age of newest backup',
                query: sprintf('max by (engine) (%s)', FrameworkMetric::BackupLastSuccessAgeSeconds->value),
                unit: 's',
                description: 'The dead-man signal for a backup schedule that stopped running rather than started failing.',
                warnThreshold: 90_000.0,
                criticalThreshold: 172_800.0,
                legend: '{{engine}}',
            ),
            new DashboardPanel(
                title: 'Engines with no backup at all',
                query: sprintf('count(%s == 0)', FrameworkMetric::BackupPresent->value),
                type: DashboardPanelType::Stat,
                criticalThreshold: 1.0,
            ),
            new DashboardPanel(
                title: 'Backup size',
                query: sprintf('max by (engine) (%s)', FrameworkMetric::BackupLastSuccessSizeBytes->value),
                unit: 'bytes',
                description: 'A sudden collapse means a backup that reported success but captured nothing.',
                legend: '{{engine}}',
            ),
        ]);
    }

    private function httpSection(): DashboardSection
    {
        return new DashboardSection('HTTP', [
            new DashboardPanel(
                title: 'Request rate by status',
                query: sprintf('sum by (status) (rate(%s[5m]))', FrameworkMetric::HttpRequestsTotal->value),
                legend: '{{status}}',
            ),
            new DashboardPanel(
                title: 'Request duration p95',
                query: sprintf('histogram_quantile(0.95, sum by (le, route) (rate(%s_bucket[5m])))', FrameworkMetric::HttpRequestDurationMs->value),
                unit: 'ms',
                legend: '{{route}}',
            ),
        ]);
    }
}
