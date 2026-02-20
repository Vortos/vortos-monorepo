<?php

namespace Fortizan\Tekton\Messenger\Transport\Kafka\Send;

use BackedEnum;
use Fortizan\Tekton\Messenger\Transport\Kafka\Stamp\KafkaTopicStamp;
use Koco\Kafka\RdKafka\RdKafkaFactory;
use Psr\Log\LoggerInterface;
use RdKafka\Producer as KafkaProducer;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

class KafkaSender implements SenderInterface
{
    private ?KafkaProducer $producer = null;

    public function __construct(
        private KafkaSenderProperties $properties,
        private SerializerInterface $serializer,
        private RdKafkaFactory $rdkafkaFactory,
        private LoggerInterface $logger
    ) {}

    public function send(Envelope $envelope): Envelope
    {
        $topicStamp = $envelope->last(KafkaTopicStamp::class);

        $topic = $this->properties->getTopicName()[0]; # my thought is to use {transport_type}_topic as the default topic name 
        if ($topicStamp !== null) {
            $topic = $topicStamp->getTopic();
        }

        $version = $topicStamp->getVersion();
        if (preg_match('/v\d+$/', $topic) !== 1) {
            $topic = $topic . '.' . $version;
        } else {
            $topic = preg_replace('/v\d+$/', $version, $topic);
        }
// dd($topic);
        $message = $this->serializer->encode($envelope);

        $producer = $this->getProducer();

        $producerTopic = $producer->newTopic($topic);

        $producerTopic->producev(
            RD_KAFKA_PARTITION_UA, 
            0, 
            $message['body'], 
            $message['key'] ?? null, 
            $message['headers'] ?? null,
            $message['timestamp_ms'] ?? null
        );

        $producer->poll(0);

        return $envelope;
    }

    public function __destruct()
    {
        if (!isset($this->producer)) {
            return;
        }

        $retries = $this->properties->getFlushRetries();
        $timeout = $this->properties->getFlushTimeout();

        $result = null;
        for ($i = 0; $i <= $retries; $i++) {
            // flush() waits up to $timeout ms for the queue to empty
            $result = $this->producer->flush($timeout);

            // If successful (queue empty), stop retrying
            if ($result === RD_KAFKA_RESP_ERR_NO_ERROR) {
                break;
            }
        }

        if($result !== RD_KAFKA_RESP_ERR_NO_ERROR){
            $this->logger->critical(
                "Kafka producer timedout before empting queue"
            );
        }
    }

    private function getProducer(): KafkaProducer
    {
        return $this->producer ?? $this->producer = $this->rdkafkaFactory->createProducer($this->properties->getKafkaConf());
    }
}
