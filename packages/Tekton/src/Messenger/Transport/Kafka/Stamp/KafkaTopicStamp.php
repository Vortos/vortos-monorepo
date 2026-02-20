<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

class KafkaTopicStamp implements StampInterface
{
    public function __construct(
        private string $topic,
        private string $version
    ){
    }

    public function getTopic():string
    {
        return $this->topic;
    }

    public function getVersion():string
    {
        return $this->version;
    }
}