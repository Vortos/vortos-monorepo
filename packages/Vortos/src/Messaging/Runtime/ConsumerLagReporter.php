<?php

declare(strict_types=1);

namespace Vortos\Messaging\Runtime;

use Vortos\Metrics\Telemetry\FrameworkTelemetry;
use Vortos\Observability\Config\ObservabilityModule;
use Vortos\Observability\Telemetry\FrameworkMetric;
use Vortos\Observability\Telemetry\FrameworkMetricLabels;
use Vortos\Observability\Telemetry\MetricLabel;
use Vortos\Observability\Telemetry\MetricLabelValue;

/**
 * Owns consumer lag/liveness metric semantics, independent of any broker driver.
 *
 * Split out of the Kafka driver on purpose. The interesting logic here is not the broker calls but
 * the guard rails around them — a never-committed offset, an unanswered watermark query, a
 * watermark/offset race — and each of those is a way to publish a *wrong* number that either fakes
 * a backlog or hides a real one. Expressed in scalars, they are directly unit-testable, whereas
 * anything touching rdkafka's types is not without the extension installed.
 *
 * A null telemetry (metrics package absent) makes every method a no-op.
 */
final class ConsumerLagReporter
{
    public function __construct(private readonly ?FrameworkTelemetry $telemetry = null) {}

    /**
     * Liveness. Reported unconditionally, before any broker query, so that a consumer which is
     * running but cannot reach the broker still proves it is alive — that is a different incident
     * from a consumer that has died, and they need different responses.
     */
    public function reportPollCycles(string $consumer, int $cycles): void
    {
        $this->telemetry?->increment(
            ObservabilityModule::Messaging,
            FrameworkMetric::MessagingConsumerPollCyclesTotal,
            FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Consumer, $consumer),
            ),
            (float) $cycles,
        );
    }

    /**
     * Zero here means subscribed but unassigned — mid-rebalance, or starved because other members
     * of the group hold every partition. That is invisible in lag alone: a consumer with no
     * partitions has no lag to report and would otherwise look perfectly healthy.
     */
    public function reportAssignedPartitions(string $consumer, string $consumerGroup, int $count): void
    {
        $this->telemetry?->setGauge(
            ObservabilityModule::Messaging,
            FrameworkMetric::MessagingConsumerAssignedPartitions,
            FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Consumer, $consumer),
                MetricLabelValue::of(MetricLabel::ConsumerGroup, $consumerGroup),
            ),
            (float) $count,
        );
    }

    /**
     * @param int      $committedOffset The group's committed offset, or a negative rdkafka sentinel.
     * @param int|null $highWatermark   Null when the broker did not answer within the timeout.
     */
    public function reportPartitionLag(
        string $consumer,
        string $consumerGroup,
        string $topic,
        int $partition,
        int $committedOffset,
        ?int $highWatermark,
    ): void {
        // A group that has never committed on this partition reports a sentinel (RD_KAFKA_OFFSET_
        // INVALID, -1001) instead of a position. Watermark-minus-sentinel would manufacture a huge
        // phantom backlog on every new consumer group and every fresh topic.
        if ($committedOffset < 0) {
            return;
        }

        // No sample beats a wrong sample: publishing 0 for an unanswered query would actively
        // silence a real backlog for as long as the broker stays unreachable.
        if ($highWatermark === null) {
            return;
        }

        // The watermark and the committed offset are read at slightly different instants, so a busy
        // partition can legitimately produce a small negative. Negative lag is meaningless and
        // would break threshold alerts.
        $lag = max(0, $highWatermark - $committedOffset);

        $this->telemetry?->setGauge(
            ObservabilityModule::Messaging,
            FrameworkMetric::MessagingConsumerLag,
            FrameworkMetricLabels::of(
                MetricLabelValue::of(MetricLabel::Consumer, $consumer),
                MetricLabelValue::of(MetricLabel::ConsumerGroup, $consumerGroup),
                MetricLabelValue::of(MetricLabel::Topic, $topic),
                MetricLabelValue::of(MetricLabel::Partition, (string) $partition),
            ),
            (float) $lag,
        );
    }
}
