<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Factory;

use Vortos\Messaging\Driver\Kafka\Runtime\KafkaConsumer;
use Vortos\Messaging\Registry\ConsumerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
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
        private ?TracingInterface $tracer = null
    ) {}

    public function create(string $consumerName): KafkaConsumer
    {
        $consumerConfig = $this->consumerRegistry->get($consumerName);
        $transportConfig = $this->transportRegistry->get($consumerConfig['transport']);

        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', str_replace('kafka://', '', $transportConfig['dsn']));
        $conf->set('socket.timeout.ms', '60000');

        $sasl = $transportConfig['security']['sasl'] ?? [];
        if (!empty($sasl)) {
            if (empty($sasl['username']) || empty($sasl['password'])) {
                throw new \InvalidArgumentException(
                    "SASL username and password must not be empty for consumer '{$consumerName}'."
                );
            }
            $conf->set('sasl.mechanisms', $sasl['mechanism']);
            $conf->set('sasl.username', $sasl['username']);
            $conf->set('sasl.password', $sasl['password']);
            $conf->set('security.protocol', 'SASL_PLAINTEXT');
        }

        $ssl = $transportConfig['security']['ssl'] ?? [];
        if (!empty($ssl)) {

            if (isset($ssl['ca_location'])) {
                if (!is_readable($ssl['ca_location'])) {
                    throw new \InvalidArgumentException("SSL CA file is not readable: {$ssl['ca_location']}");
                }
                $conf->set('ssl.ca.location', $ssl['ca_location']);
            }

            if (isset($ssl['certificate_location'])) {
                if (!is_readable($ssl['certificate_location'])) {
                    throw new \InvalidArgumentException("SSL certificate file is not readable: {$ssl['certificate_location']}");
                }
                $conf->set('ssl.certificate.location', $ssl['certificate_location']);
            }

            if (isset($ssl['key_location'])) {
                if (!is_readable($ssl['key_location'])) {
                    throw new \InvalidArgumentException("SSL key file is not readable: {$ssl['key_location']}");
                }
                $conf->set('ssl.key.location', $ssl['key_location']);
            }

            if (isset($ssl['key_password'])) {
                $conf->set('ssl.key.password', $ssl['key_password']);
            }

            if (isset($ssl['verify_peer'])) {
                $conf->set('enable.ssl.certificate.verification', $ssl['verify_peer'] ? 'true' : 'false');
            }

            $conf->set('security.protocol', 'SSL');
            if (!empty($sasl)) {
                $conf->set('security.protocol', 'SASL_SSL');
            }
        }

        $conf->set('group.id', $consumerConfig['groupId']);
        $conf->set('auto.offset.reset', $consumerConfig['kafka']['autoOffsetResetPolicy']);
        $conf->set('session.timeout.ms', (string)$consumerConfig['kafka']['sessionTimeoutMs']);
        $conf->set('max.poll.interval.ms', (string)$consumerConfig['kafka']['maxPollIntervalMs']);
        $conf->set('fetch.min.bytes', (string)$consumerConfig['kafka']['fetchMinBytes']);
        $conf->set('fetch.wait.max.ms', (string)$consumerConfig['kafka']['fetchMaxWaitMs']);
        $conf->set('enable.auto.commit', 'false');

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
            $this->tracer
        );

    }
}
