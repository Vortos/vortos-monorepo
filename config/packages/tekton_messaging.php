<?php

use Fortizan\Tekton\Messaging\DependencyInjection\TektonMessagingConfig;
use Fortizan\Tekton\Messaging\Driver\Kafka\Runtime\KafkaProducer;

return static function (TektonMessagingConfig $config): void {
    $config->driver()
        ->producer(KafkaProducer::class);
};