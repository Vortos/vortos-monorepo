<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use Vortos\Messaging\Contract\ConsumerInterface;
use Vortos\Messaging\ValueObject\ReceivedMessage;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Messaging\Runtime\ConsumerLagReporter;
use Vortos\Tracing\Contract\TracingInterface;
use Psr\Log\LoggerInterface;
use RdKafka\KafkaConsumer as RdKafkaConsumer;
use Throwable;

/**
 * Kafka implementation of ConsumerInterface using the RdKafka extension.
 * 
 * Runs a blocking poll loop with a 500ms timeout per iteration. The loop
 * exits cleanly when stop() is called (e.g. via SIGTERM signal handler).
 * 
 * Partition EOF and timeout errors are treated as normal conditions and
 * do not interrupt the loop. Only real errors are logged.
 * 
 * acknowledge() uses async commit for throughput. If you need guaranteed
 * offset commits before the process exits, call commitAsync() in your
 * shutdown handler.
 *
 * Also samples consumer lag and liveness from inside this loop (see recordLagSample()). Doing it
 * here rather than from a broker-side exporter is deliberate: an exporter reports a consumer
 * group's lag whether or not any process is alive to drain it, so a dead or never-started consumer
 * looks identical to a healthy idle one. Sampled from the poll loop, the metrics stop arriving the
 * moment the consumer does, which makes absence itself the alert.
 */
final class KafkaConsumer implements ConsumerInterface
{
    /**
     * How often to ask the broker for watermark offsets. queryWatermarkOffsets() is a blocking
     * broker round-trip, so it must never run at poll cadence — at 500ms polls that would be ~120
     * extra RPCs/minute/partition for a value that cannot meaningfully change faster than alerting
     * can react to it.
     */
    private const LAG_SAMPLE_INTERVAL_MS = 15_000;

    /** Watermark/committed-offset queries are best-effort: a slow broker must not stall consumption. */
    private const OFFSET_QUERY_TIMEOUT_MS = 1_000;

    /**
     * Consecutive empty-assignment samples before this is treated as a detachment rather than a
     * rebalance. At a 15s sample interval this is four minutes — far longer than any healthy
     * rebalance, short enough to catch a detached consumer before a backlog matters.
     */
    private const EMPTY_ASSIGNMENT_ALERT_STREAK = 16;

    private bool $running = false;
    private bool $draining = false;
    private int $lastLagSampleNs = 0;
    private int $pollCyclesSinceFlush = 0;

    /**
     * Consecutive lag samples in which this consumer held ZERO partitions.
     *
     * A consumer that loses its group membership — observed after a broker restart, where
     * librdkafka did not rejoin — keeps running, keeps polling, logs nothing, and reports no lag,
     * because a consumer with no partitions has no lag to report. Every liveness signal we have
     * (process running, container healthy, no errors) stays green while the work has stopped. It is
     * indistinguishable from a quiet topic until someone notices a missing email.
     *
     * Zero is legitimate briefly — mid-rebalance, or a group with more members than partitions — so
     * the signal is a sustained streak rather than a single observation.
     */
    private int $emptyAssignmentStreak = 0;

    /** Whether the current detachment has already been reported, so this logs once per episode. */
    private bool $detachmentReported = false;

    public function __construct(
        private RdKafkaConsumer $rdConsumer,
        private array $topics,
        private bool $asyncCommit,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer,
        private ?ConsumerLagReporter $lagReporter = null,
        private string $consumerGroup = '',
        private ?FlushableMetricsInterface $metricsFlusher = null,
        private ?PartitionWatermarkReaderInterface $watermarkReader = null,
    ) {}

    public function consume(string $consumerName, callable $handler): void
    {
        $this->running = true;
        $this->draining = false;
        $this->lastLagSampleNs = hrtime(true);
        $this->pollCyclesSinceFlush = 0;

        $this->rdConsumer->subscribe($this->topics);

        while ($this->running) {
            $rdMessage = $this->rdConsumer->consume(500);
            $this->pollCyclesSinceFlush++;
            $this->maybeRecordLagSample($consumerName);

            if ($rdMessage->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->tracer?->extractContext($rdMessage->headers ?? []);

                $handler(
                    KafkaMessage::fromRdKafkaMessage($rdMessage)
                        ->toReceivedMessage($consumerName)
                );
            } elseif (
                $rdMessage->err === RD_KAFKA_RESP_ERR__PARTITION_EOF ||
                $rdMessage->err === RD_KAFKA_RESP_ERR__TIMED_OUT
            ) {
                // Normal conditions — no messages available, continue polling
            } elseif ($rdMessage->err === RD_KAFKA_RESP_ERR__FATAL) {
                $this->logger->critical('Fatal Kafka error — consumer stopping', [
                    'error' => $rdMessage->errstr(),
                    'code'  => $rdMessage->err,
                ]);
                $this->stop();
            } else {
                $this->logger->error('Kafka consume error', [
                    'error' => $rdMessage->errstr(),
                    'code'  => $rdMessage->err,
                ]);
            }
        }

        if ($this->draining) {
            $this->flushSyncCommit();
        }
    }

    /**
     * Throttled entry point called once per poll cycle. Cheap on the vast majority of cycles — a
     * single hrtime() comparison — so the hot path stays untouched.
     */
    private function maybeRecordLagSample(string $consumerName): void
    {
        if ($this->lagReporter === null) {
            return;
        }

        $now = hrtime(true);

        if (($now - $this->lastLagSampleNs) < self::LAG_SAMPLE_INTERVAL_MS * 1_000_000) {
            return;
        }

        $this->lastLagSampleNs = $now;
        $this->recordLagSample($consumerName);

        // Export immediately, and from here rather than relying on ConsumerRunner's drain: that
        // drain only runs from the per-message callback, so an IDLE consumer never flushes. Without
        // this an idle-but-healthy consumer would stop reporting and read as dead — exactly the
        // false positive the liveness metric exists to avoid.
        $this->flushTelemetry();
    }

    private function flushTelemetry(): void
    {
        if ($this->metricsFlusher === null) {
            return;
        }

        try {
            $this->metricsFlusher->flush();
        } catch (Throwable $e) {
            $this->logger->debug('Lag sample telemetry flush failed.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Emits the liveness counter, the assigned-partition gauge, and one lag gauge per partition.
     * All metric semantics live in {@see ConsumerLagReporter}; this method only does the broker I/O
     * and hands over scalars.
     *
     * Wholly best-effort: any broker or extension failure is swallowed after a debug log. Monitoring
     * must never be able to stop the thing it monitors — an exception escaping here would kill the
     * consumer and cause the very outage the metric exists to detect.
     */
    private function recordLagSample(string $consumerName): void
    {
        if ($this->lagReporter === null) {
            return;
        }

        $this->lagReporter->reportPollCycles($consumerName, $this->pollCyclesSinceFlush);
        $this->pollCyclesSinceFlush = 0;

        try {
            $assignment = $this->rdConsumer->getAssignment();

            $this->lagReporter->reportAssignedPartitions($consumerName, $this->consumerGroup, count($assignment));

            if ($assignment === []) {
                $this->noteEmptyAssignment($consumerName);

                return;
            }

            $this->noteHealthyAssignment($consumerName);

            $committed = $this->rdConsumer->getCommittedOffsets($assignment, self::OFFSET_QUERY_TIMEOUT_MS);

            foreach ($committed as $topicPartition) {
                $topic = $topicPartition->getTopic();
                $partition = $topicPartition->getPartition();

                $this->lagReporter->reportPartitionLag(
                    $consumerName,
                    $this->consumerGroup,
                    $topic,
                    $partition,
                    $topicPartition->getOffset(),
                    $this->watermarkReader?->highWatermark($topic, $partition, self::OFFSET_QUERY_TIMEOUT_MS),
                );
            }
        } catch (Throwable $e) {
            $this->logger->debug('Kafka lag sample skipped.', [
                'consumer' => $consumerName,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    public function stop(): void
    {
        $this->draining = true;
        $this->running = false;
    }

    public function isDraining(): bool
    {
        return $this->draining;
    }

    public function acknowledge(ReceivedMessage $message): void
    {
        $this->commit();
    }

    public function reject(ReceivedMessage $message, bool $requeue = false): void
    {
        if ($requeue) {
            throw new \LogicException(
                'Kafka does not support server-side requeue. To redeliver, do not commit the offset — simply omit the acknowledge() call instead of calling reject(requeue: true).'
            );
        }

        $this->commit();
    }

    private function commit($message_or_offsets = null): void
    {
        if ($this->asyncCommit) {
            $this->rdConsumer->commitAsync($message_or_offsets);
        } else {
            $this->rdConsumer->commit($message_or_offsets);
        }
    }

    private function flushSyncCommit(): void
    {
        try {
            $this->rdConsumer->commit();
            $this->logger->info('Drain: final synchronous offset commit completed.');
        } catch (\RdKafka\Exception $e) {
            if ($e->getCode() === RD_KAFKA_RESP_ERR__NO_OFFSET) {
                return;
            }
            $this->logger->warning('Drain: final offset commit failed — message may be redelivered (safe via inbox).', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A sample in which this consumer held no partitions.
     *
     * Escalates to an error only once a streak makes a transient rebalance implausible, and only
     * once per episode — a poll loop would otherwise turn a detached consumer into a log flood.
     */
    private function noteEmptyAssignment(string $consumerName): void
    {
        $this->emptyAssignmentStreak++;

        if ($this->emptyAssignmentStreak < self::EMPTY_ASSIGNMENT_ALERT_STREAK || $this->detachmentReported) {
            return;
        }

        $this->detachmentReported = true;

        $this->logger->error(
            'Consumer is subscribed but holds no partitions; it is consuming nothing.',
            [
                'consumer' => $consumerName,
                'consumer_group' => $this->consumerGroup,
                'topics' => $this->topics,
                'consecutive_empty_samples' => $this->emptyAssignmentStreak,
                'seconds_without_partitions' => (int) ($this->emptyAssignmentStreak * (self::LAG_SAMPLE_INTERVAL_MS / 1000)),
                'likely_cause' => 'Lost group membership without rejoining — observed after a broker '
                    . 'restart. A group with more members than partitions is the benign explanation.',
                'remediation' => 'Check the group with kafka-consumer-groups --describe --state. If it '
                    . 'reports no members, restart this worker to force a rejoin.',
            ],
        );
    }

    /** Partitions are held again; reset so a later detachment is reported afresh. */
    private function noteHealthyAssignment(string $consumerName): void
    {
        if ($this->detachmentReported) {
            $this->logger->info('Consumer regained partitions.', [
                'consumer' => $consumerName,
                'consumer_group' => $this->consumerGroup,
            ]);
        }

        $this->emptyAssignmentStreak = 0;
        $this->detachmentReported = false;
    }
}
