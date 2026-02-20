<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messenger\Transport\Kafka;

use Fortizan\Tekton\Messenger\Transport\Kafka\KafkaTransport;
use Fortizan\Tekton\Messenger\Transport\Kafka\Receive\KafkaReceiverProperties;
use Fortizan\Tekton\Messenger\Transport\Kafka\Send\KafkaSenderProperties;
use Fortizan\Tekton\Messenger\Transport\Kafka\Serialization\KafkaSerializerDecorator;
use Koco\Kafka\RdKafka\RdKafkaFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RdKafka\Conf as KafkaConf;
use RdKafka\KafkaConsumer;
use RdKafka\TopicPartition;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class KafkaTransportFactory implements TransportFactoryInterface
{
    private const DSN_PROTOCOLS = [
        'kafka://',
        'kafka+ssl://',
    ];

    private LoggerInterface $logger;
    private RdKafkaFactory $kafkaFactory;

    public function __construct(
        RdKafkaFactory $kafkaFactory,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->kafkaFactory = $kafkaFactory;
    }

    public function supports(string $dsn, array $options): bool
    {
        foreach (self::DSN_PROTOCOLS as $protocol) {
            if (str_starts_with($dsn, $protocol)) {
                return true;
            }
        }

        return false;
    }

    public function createTransport(string $dsn, array $options, SerializerInterface $serializer): TransportInterface
    {
        $serializerDecorator = new KafkaSerializerDecorator($serializer);

        $conf = new KafkaConf();

        // Set rebalance callback
        $conf->setRebalanceCb($this->createRebalanceCb($this->logger));

        $brokers = $this->stripProtocol($dsn);
        $conf->set('metadata.broker.list', implode(',', $brokers));

        // Apply Kafka configuration
        foreach (array_merge($options['topic_conf'] ?? [], $options['kafka_conf'] ?? []) as $option => $value) {
            $conf->set($option, (string) $value);
        }

        // Extract topic name(s) - handle both formats
        $topicName = $options['topic'];
        if (is_array($topicName) && isset($topicName['name'])) {
            $topicName = $topicName['name'];
        }

        return new KafkaTransport(
            $this->logger,
            $serializerDecorator,
            $this->kafkaFactory,
            new KafkaSenderProperties(
                $conf,
                $topicName,
                (int) $options['flushTimeout'] ?? 10000,
                (int) $options['flushRetries'] ?? 0
            ),
            new KafkaReceiverProperties(
                $conf,
                $topicName,
                (int) $options['receiveTimeout'] ?? 10000,
                (bool) $options['commitAsync'] ?? false,
                (int) ($options['batch_size'] ?? 1)
            )
        );
    }

    private function stripProtocol(string $dsn): array
    {
        $brokers = [];
        foreach (explode(',', $dsn) as $currentBroker) {
            foreach (self::DSN_PROTOCOLS as $protocol) {
                $currentBroker = str_replace($protocol, '', $currentBroker);
            }
            $brokers[] = $currentBroker;
        }

        return $brokers;
    }

    private function createRebalanceCb(LoggerInterface $logger): \Closure
    {
        return function (KafkaConsumer $kafka, $err, ?array $topicPartitions = null) use ($logger) {
            $topicPartitions = $topicPartitions ?? [];

            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    /** @var TopicPartition $topicPartition */
                    foreach ($topicPartitions as $topicPartition) {
                        $logger->info(sprintf(
                            'Assign: %s %s %s',
                            $topicPartition->getTopic(),
                            $topicPartition->getPartition(),
                            $topicPartition->getOffset()
                        ));
                    }
                    $kafka->assign($topicPartitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    /** @var TopicPartition $topicPartition */
                    foreach ($topicPartitions as $topicPartition) {
                        $logger->info(sprintf(
                            'Revoke: %s %s %s',
                            $topicPartition->getTopic(),
                            $topicPartition->getPartition(),
                            $topicPartition->getOffset()
                        ));
                    }
                    $kafka->assign(null);
                    break;

                default:
                    throw new \Exception((string) $err);
            }
        };
    }
}
