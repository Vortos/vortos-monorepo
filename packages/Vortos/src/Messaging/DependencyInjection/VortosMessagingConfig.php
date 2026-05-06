<?php

declare(strict_types=1);

namespace Vortos\Messaging\DependencyInjection;

use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;

final class VortosMessagingConfig
{
    private array $driverConfig = [];

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
    }

    public function driver(): DriverConfig
    {
        return new DriverConfig($this->driverConfig);
    }

    public function toArray(): array
    {
        return ['driver' => $this->driverConfig];
    }
}
