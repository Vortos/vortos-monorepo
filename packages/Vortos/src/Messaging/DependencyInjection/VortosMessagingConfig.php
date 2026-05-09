<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;

final class VortosMessagingConfig
{
    private array $driverConfig = [];
    private OutboxConfig $outboxConfig;
    private DlqConfig $dlqConfig;
    private ConsumerDefaultsConfig $consumerDefaultsConfig;

    public function __construct()
    {
        $this->driverConfig = match ($_ENV['VORTOS_MESSAGING_DRIVER'] ?? 'in-memory') {
            'kafka' => [
                'producer' => LazyKafkaProducer::class,
                'consumer' => null,
            ],
            default => [
                'producer' => InMemoryProducer::class,
                'consumer' => InMemoryConsumer::class,
            ],
        };

        $this->outboxConfig = new OutboxConfig();
        $this->dlqConfig = new DlqConfig();
        $this->consumerDefaultsConfig = new ConsumerDefaultsConfig();
    }

    public function driver(): DriverConfig
    {
        return new DriverConfig($this->driverConfig);
    }

    public function outbox(): OutboxConfig
    {
        return $this->outboxConfig;
    }

    public function dlq(): DlqConfig
    {
        return $this->dlqConfig;
    }

    public function consumerDefaults(): ConsumerDefaultsConfig
    {
        return $this->consumerDefaultsConfig;
    }

    public function toArray(): array
    {
        return [
            'driver'            => $this->driverConfig,
            'outbox'            => $this->outboxConfig->toArray(),
            'dlq'               => $this->dlqConfig->toArray(),
            'consumer_defaults' => $this->consumerDefaultsConfig->toArray(),
        ];
    }
}
