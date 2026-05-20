<?php

declare(strict_types=1);

namespace App\User\Infrastructure\Messaging;

use App\User\Domain\Event\UserRegisteredEvent;
use Vortos\Messaging\Attribute\MessagingConfig;
use Vortos\Messaging\Attribute\RegisterConsumer;
use Vortos\Messaging\Attribute\RegisterProducer;
use Vortos\Messaging\Attribute\RegisterTransport;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaConsumerDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaProducerDefinition;
use Vortos\Messaging\Driver\Kafka\Definition\KafkaTransportDefinition;

#[MessagingConfig]
final class UserMessagingConfig
{
    #[RegisterTransport]
    public function userEventsTransport(): KafkaTransportDefinition
    {
        return KafkaTransportDefinition::create('user.events')
            ->dsn($_ENV['KAFKA_DSN'] ?? 'kafka://kafka:9092')
            ->topic('user.events')
            ->partitions(6)
            ->replicationFactor(3);
    }

    #[RegisterProducer]
    public function userEventsProducer(): KafkaProducerDefinition
    {
        return KafkaProducerDefinition::create('user.events')
            ->transport('user.events')
            ->outbox(true)
            ->publishes(UserRegisteredEvent::class);
    }

    #[RegisterConsumer]
    public function userEventsConsumer(): KafkaConsumerDefinition
    {
        return KafkaConsumerDefinition::create('user.events')
            ->groupId('user-service')
            ->parallelism(4)
            ->inProcess(true);
    }
}
