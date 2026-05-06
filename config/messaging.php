<?php

use Vortos\Messaging\DependencyInjection\VortosMessagingConfig;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryConsumer;
use Vortos\Messaging\Driver\InMemory\Runtime\InMemoryProducer;
use Vortos\Messaging\Driver\Kafka\Runtime\LazyKafkaProducer;

return static function (VortosMessagingConfig $config): void {
    if (($_ENV['VORTOS_MESSAGING_DRIVER'] ?? 'kafka') === 'in-memory') {
        $config->driver()
            ->producer(InMemoryProducer::class)
            ->consumer(InMemoryConsumer::class);

        return;
    }

    $config->driver()
        ->producer(LazyKafkaProducer::class);
};
