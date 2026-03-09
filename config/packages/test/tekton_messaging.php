<?php

use Fortizan\Tekton\Messaging\DependencyInjection\TektonMessagingConfig;
use Fortizan\Tekton\Messaging\Driver\InMemory\Runtime\InMemoryProducer;

return static function (TektonMessagingConfig $config): void {
    $config->driver()
        ->producer(InMemoryProducer::class);
};