<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Factory;

use Vortos\Messaging\Driver\Kafka\Runtime\KafkaProducer;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Vortos\Tracing\Contract\TracingInterface;

/**
 * Builds a KafkaProducer instance configured from a transportDefinition.
 *
 * Reads DSN, SASL, and SSL config from the transport registry and applies
 * them to an RdKafka\Conf before constructing the RdKafka\Producer.
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

        $conf = new \RdKafka\Conf();
        $conf->set('metadata.broker.list', str_replace('kafka://', '', $config['dsn']));
        $conf->set('socket.timeout.ms', '60000');

        $sasl = $config['security']['sasl'] ?? [];
        if (!empty($sasl)) {
            if (empty($sasl['username']) || empty($sasl['password'])) {
                throw new \InvalidArgumentException(
                    "SASL username and password must not be empty for transport '{$transportName}'."
                );
            }
            $conf->set('sasl.mechanisms', $sasl['mechanism']);
            $conf->set('sasl.username', $sasl['username']);
            $conf->set('sasl.password', $sasl['password']);
            $conf->set('security.protocol', 'SASL_PLAINTEXT');
        }

        $ssl = $config['security']['ssl'] ?? [];
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
