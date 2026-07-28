<?php

declare(strict_types=1);

namespace Vortos\Observability\Dashboard;

use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\MetricNamespace;

/**
 * The panels every Vortos deployment wants, defined against {@see FrameworkMetric} rather than
 * hand-typed metric strings.
 *
 * A dashboard that lives only in Grafana Cloud is not reviewable, not versioned, and silently rots
 * when a metric is renamed — you find out during an incident, staring at an empty graph. Generating
 * it from the metric enum means a rename is a compile-time break here instead.
 *
 * Queries are rendered through a {@see MetricNamespace}, because {@see FrameworkMetric} holds the
 * *undecorated* name while adapters emit '{namespace}_{name}'. Reading ->value directly here is what
 * produced dashboards that matched nothing on every deployment that configured a namespace — the
 * exact silent rot this class was written to prevent, reintroduced one layer up.
 *
 * The queries assume a Prometheus-compatible store (Grafana Cloud / Mimir), which is what the OTLP
 * pipeline writes into.
 */
final class FrameworkDashboardCatalog
{
    private readonly MetricNamespace $namespace;

    public function __construct(?MetricNamespace $namespace = null)
    {
        $this->namespace = $namespace ?? MetricNamespace::default();
    }

    /**
     * The fully-qualified series name for a metric, as the configured adapter actually emits it.
     */
    private function metric(FrameworkMetric $metric): string
    {
        return $this->namespace->forMetric($metric);
    }

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
                query: sprintf('sum by (transport, event) (%s)', $this->metric(FrameworkMetric::DlqBacklogSize)),
                type: DashboardPanelType::TimeSeries,
                description: 'Any value above zero is work a customer asked for that was dropped. There is no healthy steady state.',
                criticalThreshold: 1.0,
                legend: '{{transport}} / {{event}}',
            ),
            new DashboardPanel(
                title: 'Oldest stuck outbox message',
                query: sprintf('max by (transport) (%s)', $this->metric(FrameworkMetric::OutboxOldestPendingAgeSeconds)),
                unit: 's',
                description: 'Depth alone hides a stalled relay: a queue of three that never drains looks identical to a healthy one. Age is what exposes it.',
                warnThreshold: 300.0,
                criticalThreshold: 900.0,
                legend: '{{transport}}',
            ),
            new DashboardPanel(
                title: 'Consumer lag',
                query: sprintf('sum by (consumer, topic) (%s)', $this->metric(FrameworkMetric::MessagingConsumerLag)),
                description: 'Committed offset versus partition high watermark, sampled inside the consumer itself.',
                legend: '{{consumer}} / {{topic}}',
            ),
            new DashboardPanel(
                title: 'Consumer liveness (poll cycles/sec)',
                query: sprintf('sum by (consumer) (rate(%s[5m]))', $this->metric(FrameworkMetric::MessagingConsumerPollCyclesTotal)),
                description: 'A flat line means the consumer process is gone or wedged — distinct from a consumer that is alive with nothing to do.',
                criticalThreshold: 0.0,
                legend: '{{consumer}}',
            ),
            new DashboardPanel(
                title: 'Consumers with no partitions assigned',
                query: sprintf('count(%s == 0)', $this->metric(FrameworkMetric::MessagingConsumerAssignedPartitions)),
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
                query: sprintf('count(%s == 0)', $this->metric(FrameworkMetric::SupervisorProgramUp)),
                type: DashboardPanelType::Stat,
                description: 'Supervised programs in any state other than RUNNING.',
                criticalThreshold: 1.0,
            ),
            new DashboardPanel(
                title: 'Restart rate',
                query: sprintf('sum by (program) (rate(%s[15m]))', $this->metric(FrameworkMetric::SupervisorProgramRestartsTotal)),
                description: 'A crash-looping program reports RUNNING in every individual sample; only the respawn rate reveals it.',
                legend: '{{program}}',
            ),
            new DashboardPanel(
                title: 'Worker memory',
                query: sprintf('sum by (program) (%s)', $this->metric(FrameworkMetric::SupervisorProgramMemoryBytes)),
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
                query: sprintf('max by (engine) (%s)', $this->metric(FrameworkMetric::BackupLastSuccessAgeSeconds)),
                unit: 's',
                description: 'The dead-man signal for a backup schedule that stopped running rather than started failing.',
                warnThreshold: 90_000.0,
                criticalThreshold: 172_800.0,
                legend: '{{engine}}',
            ),
            new DashboardPanel(
                title: 'Engines with no backup at all',
                query: sprintf('count(%s == 0)', $this->metric(FrameworkMetric::BackupPresent)),
                type: DashboardPanelType::Stat,
                criticalThreshold: 1.0,
            ),
            new DashboardPanel(
                title: 'Backup size',
                query: sprintf('max by (engine) (%s)', $this->metric(FrameworkMetric::BackupLastSuccessSizeBytes)),
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
                query: sprintf('sum by (status) (rate(%s[5m]))', $this->metric(FrameworkMetric::HttpRequestsTotal)),
                legend: '{{status}}',
            ),
            new DashboardPanel(
                title: 'Request duration p95',
                query: sprintf('histogram_quantile(0.95, sum by (le, route) (rate(%s_bucket[5m])))', $this->metric(FrameworkMetric::HttpRequestDurationMs)),
                unit: 'ms',
                legend: '{{route}}',
            ),
        ]);
    }
}
