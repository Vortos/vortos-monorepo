<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

class KafkaTimestampStamp implements StampInterface
{
    public function __construct(
        private int $timestampMs
    ) {}

    public function getTimestampMs(): int
    {
        return $this->timestampMs;
    }
}
