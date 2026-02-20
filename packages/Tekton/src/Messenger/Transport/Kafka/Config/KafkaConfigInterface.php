<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Config;

interface KafkaConfigInterface
{
    public static function getTopics(): array;
    public static function getProducers(): array;
    public static function getConsumers(): array;
}