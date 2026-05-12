<?php

declare(strict_types=1);

namespace Vortos\Metrics\AutoInstrumentation;

use Doctrine\DBAL\Connection;
use Vortos\Metrics\Contract\MetricsCollectorInterface;
use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Collects outbox and dead-letter queue backlog gauges.
 *
 * Runs only from metrics scrape/collection paths, never from normal requests.
 * Queries are constrained to indexed status columns used by the messaging
 * migrations, and labels are limited to transport/status/event class.
 */
final class OperationalMessagingMetricsCollector implements MetricsCollectorInterface
{
    /** @var array<string, array<string, string>> */
    private array $previousOutboxBacklogLabels = [];
    /** @var array<string, array<string, string>> */
    private array $previousOutboxAgeLabels = [];
    /** @var array<string, array<string, string>> */
    private array $previousDlqBacklogLabels = [];
    /** @var array<string, array<string, string>> */
    private array $previousDlqAgeLabels = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly FrameworkTelemetry $telemetry,
        private readonly string $outboxTable = 'vortos_outbox',
        private readonly string $deadLetterTable = 'vortos_failed_messages',
    ) {
        $this->assertSafeIdentifier($outboxTable);
        $this->assertSafeIdentifier($deadLetterTable);
    }

    public function collect(): void
    {
        try {
            $this->collectOutboxBacklog();
            $this->collectOutboxOldestPendingAge();
            $this->collectDlqBacklog();
            $this->collectDlqOldestFailedAge();
        } catch (\Throwable) {
            // Metrics collection must never break /metrics or scheduled collection.
        }
    }

    private function collectOutboxBacklog(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name, status, COUNT(*) AS backlog
             FROM {$this->outboxTable}
             WHERE status IN ('pending', 'failed')
             GROUP BY transport_name, status",
        );

        $current = [];
        foreach ($rows as $row) {
            $labels = [
                'transport' => $this->label((string) $row['transport_name']),
                'status' => $this->label((string) $row['status']),
            ];
            $current[$this->labelKey($labels)] = $labels;
            $this->telemetry->setGauge(ObservabilityModule::Messaging, FrameworkMetric::OutboxBacklogSize, $this->labels($labels), (float) $row['backlog']);
        }

        $this->zeroMissing('outbox_backlog_size', $this->previousOutboxBacklogLabels, $current);
        $this->previousOutboxBacklogLabels = $current;
    }

    private function collectOutboxOldestPendingAge(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name, MIN(created_at) AS oldest_created_at
             FROM {$this->outboxTable}
             WHERE status = 'pending'
             GROUP BY transport_name",
        );

        $now = time();
        $current = [];
        foreach ($rows as $row) {
            $oldest = strtotime((string) $row['oldest_created_at']);
            if ($oldest === false) {
                continue;
            }

            $labels = [
                'transport' => $this->label((string) $row['transport_name']),
            ];
            $current[$this->labelKey($labels)] = $labels;
            $this->telemetry->setGauge(ObservabilityModule::Messaging, FrameworkMetric::OutboxOldestPendingAgeSeconds, $this->labels($labels), (float) max(0, $now - $oldest));
        }

        $this->zeroMissing('outbox_oldest_pending_age_seconds', $this->previousOutboxAgeLabels, $current);
        $this->previousOutboxAgeLabels = $current;
    }

    private function collectDlqBacklog(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name, event_class, COUNT(*) AS backlog
             FROM {$this->deadLetterTable}
             WHERE status = 'failed'
             GROUP BY transport_name, event_class",
        );

        $current = [];
        foreach ($rows as $row) {
            $labels = [
                'transport' => $this->label((string) $row['transport_name']),
                'event' => $this->eventLabel((string) $row['event_class']),
            ];
            $current[$this->labelKey($labels)] = $labels;
            $this->telemetry->setGauge(ObservabilityModule::Messaging, FrameworkMetric::DlqBacklogSize, $this->labels($labels), (float) $row['backlog']);
        }

        $this->zeroMissing('dlq_backlog_size', $this->previousDlqBacklogLabels, $current);
        $this->previousDlqBacklogLabels = $current;
    }

    private function collectDlqOldestFailedAge(): void
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT transport_name, MIN(failed_at) AS oldest_failed_at
             FROM {$this->deadLetterTable}
             WHERE status = 'failed'
             GROUP BY transport_name",
        );

        $now = time();
        $current = [];
        foreach ($rows as $row) {
            $oldest = strtotime((string) $row['oldest_failed_at']);
            if ($oldest === false) {
                continue;
            }

            $labels = [
                'transport' => $this->label((string) $row['transport_name']),
            ];
            $current[$this->labelKey($labels)] = $labels;
            $this->telemetry->setGauge(ObservabilityModule::Messaging, FrameworkMetric::DlqOldestFailedAgeSeconds, $this->labels($labels), (float) max(0, $now - $oldest));
        }

        $this->zeroMissing('dlq_oldest_failed_age_seconds', $this->previousDlqAgeLabels, $current);
        $this->previousDlqAgeLabels = $current;
    }

    private function label(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return 'unknown';
        }

        return strlen($value) <= 128 ? $value : substr(hash('xxh128', $value), 0, 16);
    }

    private function eventLabel(string $eventClass): string
    {
        $eventClass = trim($eventClass, '\\');
        if ($eventClass === '') {
            return 'unknown';
        }

        $parts = explode('\\', $eventClass);
        return $this->label((string) end($parts));
    }

    /**
     * @param array<string, array<string, string>> $previous
     * @param array<string, array<string, string>> $current
     */
    private function zeroMissing(string $metric, array $previous, array $current): void
    {
        foreach ($previous as $key => $labels) {
            if (!isset($current[$key])) {
                $frameworkMetric = FrameworkMetric::tryFrom($metric);
                if ($frameworkMetric !== null) {
                    $this->telemetry->setGauge(ObservabilityModule::Messaging, $frameworkMetric, $this->labels($labels), 0.0);
                }
            }
        }
    }

    /** @param array<string, string> $labels */
    private function labels(array $labels): FrameworkMetricLabels
    {
        $values = [];
        foreach ($labels as $key => $value) {
            $label = MetricLabel::tryFrom($key);
            if ($label !== null) {
                $values[] = MetricLabelValue::of($label, $value);
            }
        }

        return FrameworkMetricLabels::of(...$values);
    }

    /**
     * @param array<string, string> $labels
     */
    private function labelKey(array $labels): string
    {
        ksort($labels);
        return hash('xxh128', json_encode($labels, JSON_THROW_ON_ERROR));
    }

    private function assertSafeIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException('Operational metrics table names must be safe SQL identifiers.');
        }
    }
}
