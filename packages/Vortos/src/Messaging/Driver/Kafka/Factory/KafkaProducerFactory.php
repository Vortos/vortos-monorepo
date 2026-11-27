<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Factory;

use Vortos\Messaging\Driver\Kafka\Runtime\KafkaClientConfig;
use Vortos\Messaging\Driver\Kafka\Runtime\KafkaProducer;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Builds a KafkaProducer instance configured from a transportDefinition.
 *
 * Its connection (brokers, sign-in, TLS) comes from {@see KafkaClientConfig}, as every Kafka client's does.
 * Called by MessagingExtension to produce a configured KafkaProducer
 * per transport at container compile time or lazily at runtime.
 */
final class KafkaProducerFactory
{
    public function __construct(
        private SerializerLocator $serializerLocator,
        private TransportRegistry $transportRegistry,
        private ?TracingInterface $tracer = null
    ) {}

    public function create(string $transportName): KafkaProducer
    {
        $config = $this->transportRegistry->get($transportName);

        $conf = KafkaClientConfig::conf($config, $transportName, ['socket.timeout.ms' => '60000']);

        $rdProducer = new \RdKafka\Producer($conf);

        return new KafkaProducer(
            $rdProducer, 
            $this->serializerLocator, 
            $this->transportRegistry,
            $this->tracer,
            'json'
        );
    }
}
