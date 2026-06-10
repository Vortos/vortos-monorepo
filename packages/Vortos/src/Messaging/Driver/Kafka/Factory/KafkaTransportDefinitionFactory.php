<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Factory;

use Vortos\Messaging\Definition\Transport\TransportDefinitionFactoryInterface;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaTransportDefinition;
use Vortos\Messaging\Driver\Kafka\ValueObject\SaslConfig;
use Vortos\Messaging\Driver\Kafka\ValueObject\SslConfig;
use InvalidArgumentException;

/**
 * Constructs KafkaTransportDefinition instances from raw config arrays.
 *
 * Used by the compiler pass when transport definitions are provided as arrays
 * rather than via fluent PHP builders. Mirrors the structure produced by
 * KafkaTransportDefinition::toArray() — making the two fully reversible.
 * SASL mechanism strings are matched case-sensitively.
 */
final class KafkaTransportDefinitionFactory implements TransportDefinitionFactoryInterface
{
    public function supports(string $driver): bool
    {
        return $driver === 'kafka';
    }

    public function create(string $name, array $config): KafkaTransportDefinition
    {
        $definition = KafkaTransportDefinition::create($name)
            ->dsn($config['dsn'] ?? '')
            ->topic($config['subscription']['topic'] ?? '')
            ->serializer($config['serializer'] ?? 'json');

        if (isset($config['provisioning']['partitions'])) {
            $definition->partitions($config['provisioning']['partitions']);
        }

        if (isset($config['provisioning']['replication'])) {
            $definition->replicationFactor($config['provisioning']['replication']);
        }

        if (!empty($config['provisioning']['topic_config'])) {
            $definition->topicConfig($config['provisioning']['topic_config']);
        }

        if (isset($config['security']['sasl'])) {
            $sasl = $config['security']['sasl'];
            $definition->security(match ($sasl['mechanism']) {
                'PLAIN' => SaslConfig::plain($sasl['username'], $sasl['password']),
                'SCRAM-SHA-256' => SaslConfig::scramSha256($sasl['username'], $sasl['password']),
                'SCRAM-SHA-512' => SaslConfig::scramSha512($sasl['username'], $sasl['password']),
                default => throw new InvalidArgumentException(
                    "Unsupported SASL mechanism: {$sasl['mechanism']}"
                ),
            });
        }

        if (isset($config['security']['ssl'])) {
            $ssl = $config['security']['ssl'];
            $sslConfig = SslConfig::create();
            if (isset($ssl['ca_location'])) $sslConfig->ca($ssl['ca_location']);
            if (isset($ssl['certificate_location'])) $sslConfig->cert($ssl['certificate_location']);
            if (isset($ssl['key_location'])) $sslConfig->key($ssl['key_location'], $ssl['key_password'] ?? null);
            if (isset($ssl['verify_peer'])) $sslConfig->verifyPeer($ssl['verify_peer']);
            $definition->ssl($sslConfig);
        }

        return $definition;
    }
}