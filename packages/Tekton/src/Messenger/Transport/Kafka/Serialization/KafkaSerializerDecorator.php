<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Serialization;

use Fortizan\Tekton\Messenger\Transport\Kafka\Stamp\KafkaTimestampStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class KafkaSerializerDecorator implements SerializerInterface
{
    public function __construct(
        private SerializerInterface $inner
    ){
    }

    public function encode(Envelope $envelope): array
    {
        $timestampStamp = $envelope->last(KafkaTimestampStamp::class);

        $timestamp = null;
        if($timestampStamp !== null){
            $timestamp = $timestampStamp->getTimestampMs();
        }

        $message = $this->inner->encode($envelope);

        $message['timestamp_ms'] = $timestamp;

        return $message;
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        return $this->inner->decode($encodedEnvelope);
    }
}