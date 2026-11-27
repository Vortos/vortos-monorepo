<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Factory;

use Vortos\Messaging\Driver\Kafka\Runtime\ConsumerInstanceId;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaClientConfig;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaConsumer;
use Vortos\Messaging\Driver\Kafka\Runtime\RdKafkaWatermarkReader;
use Vortos\Metrics\Contract\FlushableMetricsInterface;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Runtime\ConsumerLagReporter;
use Vortos\Tracing\Contract\TracingInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a KafkaConsumer instance configured from KafkaConsumerDefinition
 * and its associated KafkaTransportDefinition.
 *
 * Reads group ID, offset policy, poll intervals, SASL, SSL, and topic
 * from the registries and applies them to an RdKafka\Conf. Sets the
 * rebalance callback required for manual partition assignment.
 * Called by ConsumeCommand to produce a consumer per named pipeline.
 */
final class KafkaConsumerFactory
{
    public function __construct(
        private ConsumerRegistry $consumerRegistry,
        private TransportRegistry $transportRegistry,
        private LoggerInterface $logger,
        private ?TracingInterface $tracer = null,
        private ?ConsumerLagReporter $lagReporter = null,
        private ?FlushableMetricsInterface $metricsFlusher = null
    ) {}

    /**
     * The deployment may shorten the session timeout for every consumer (VORTOS_CONSUMER_SESSION_TIMEOUT_MS).
     *
     * A static member that stops does not leave its group; its partitions wait for the session timeout before
     * they move. A short timeout bounds that wait — at a deploy the old colour's partitions reach the new one
     * within it — while still outlasting a supervisor restart. A value outside Kafka's accepted range is
     * refused rather than silently clamped.
     */
    private function sessionTimeoutMs(int $declared): int
    {
        $override = getenv('VORTOS_CONSUMER_SESSION_TIMEOUT_MS');
        if (!is_string($override) || trim($override) === '') {
            return $declared;
        }
        if (!ctype_digit(trim($override)) || (int) $override < 6000 || (int) $override > 1800000) {
            throw new \InvalidArgumentException(sprintf(
                'VORTOS_CONSUMER_SESSION_TIMEOUT_MS must be a whole number of milliseconds between 6000 and 1800000, got "%s".',
                $override,
            ));
        }

        return (int) $override;
    }

    public function create(string $consumerName): KafkaConsumer
    {
        $consumerConfig = $this->consumerRegistry->get($consumerName);
        $transportConfig = $this->transportRegistry->get($consumerConfig['transport']);

        $conf = KafkaClientConfig::conf($transportConfig, $consumerConfig['transport'], ['socket.timeout.ms' => '60000']);

        $conf->set('group.id', $consumerConfig['groupId']);
        $conf->set('auto.offset.reset', $consumerConfig['kafka']['autoOffsetResetPolicy']);
        $conf->set('session.timeout.ms', (string) $this->sessionTimeoutMs((int) $consumerConfig['kafka']['sessionTimeoutMs']));
        $conf->set('max.poll.interval.ms', (string)$consumerConfig['kafka']['maxPollIntervalMs']);
        $conf->set('fetch.min.bytes', (string)$consumerConfig['kafka']['fetchMinBytes']);
        $conf->set('fetch.wait.max.ms', (string)$consumerConfig['kafka']['fetchMaxWaitMs']);
        $conf->set('enable.auto.commit', 'false');

        // Static membership, when the deployment names this process's slot. A restarted process rejoins as the
        // same member within the session timeout and keeps its partitions, so a memory-cap restart or a crash
        // does not make every other consumer in the group give its partitions up. The protocol stays eager on
        // purpose: a group cannot move to cooperative rebalancing while old and new members overlap in a
        // blue/green cutover without the new members being refused.
        $instance = getenv('VORTOS_CONSUMER_INSTANCE');
        if (is_string($instance) && trim($instance) !== '') {
            $conf->set('group.instance.id', ConsumerInstanceId::for($instance, $consumerName));
        }

        $conf->setRebalanceCb(function (\RdKafka\KafkaConsumer $kafka, int $err, ?array $partitions) {
            if ($err === RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS) {
                $this->logger->debug('Kafka partitions assigned', ['count' => count($partitions ?? [])]);
                $kafka->assign($partitions);
            } elseif ($err === RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS) {
                $this->logger->debug('Kafka partitions revoked');
                $kafka->assign([]);
            }
        });

        $rdKafkaConsumer = new \RdKafka\KafkaConsumer($conf);

        $asyncCommit = $consumerConfig['kafka']['asyncCommit'] ?? true;

        $topics = [$transportConfig['subscription']['topic']];

        return new KafkaConsumer(
            $rdKafkaConsumer,
            $topics,
            $asyncCommit,
            $this->logger,
            $this->tracer,
            $this->lagReporter,
            (string) $consumerConfig['groupId'],
            $this->metricsFlusher,
            new RdKafkaWatermarkReader($rdKafkaConsumer),
        );

    }
}
