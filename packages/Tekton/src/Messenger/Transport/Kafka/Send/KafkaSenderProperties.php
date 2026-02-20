<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Send;

use RdKafka\Conf as KafkaConf;

final class KafkaSenderProperties
{

    public function __construct(
        private KafkaConf $kafkaConf,
        private array $topicName,
        private int $flushTimeout,
        private int $flushRetries,
    ) {}

    public function getKafkaConf(): KafkaConf
    {
        return $this->kafkaConf;
    }

    public function getTopicName(): array
    {
        return $this->topicName;
    }

    public function getFlushTimeout(): int
    {
        return $this->flushTimeout;
    }

    public function getFlushRetries(): int
    {
        return $this->flushRetries;
    }
}
