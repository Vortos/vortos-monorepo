<?php

declare(strict_types=1);

namespace Fortizan\Tekton\Messenger\Transport\Kafka;

use Fortizan\Tekton\Messenger\Transport\Kafka\Receive\KafkaReceiver;
use Fortizan\Tekton\Messenger\Transport\Kafka\Receive\KafkaReceiverProperties;
use Fortizan\Tekton\Messenger\Transport\Kafka\Send\KafkaSender;
use Fortizan\Tekton\Messenger\Transport\Kafka\Send\KafkaSenderProperties;
use Koco\Kafka\RdKafka\RdKafkaFactory;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

class KafkaTransport implements TransportInterface
{
    private ?KafkaSender $sender = null;
    private ?KafkaReceiver $receiver = null;

    public function __construct(
        private LoggerInterface $logger,
        private SerializerInterface $serializer,
        private RdKafkaFactory $rdKafkaFactory,
        private KafkaSenderProperties $senderProperties,
        private KafkaReceiverProperties $receiverProperties
    ) {}

    public function get(): iterable
    {
        return $this->getReceiver()->get();
    }

    public function ack(Envelope $envelope): void
    {
        $this->getReceiver()->ack($envelope);
    }

    public function reject(Envelope $envelope): void
    {
        $this->getReceiver()->reject($envelope);
    }

    public function send(Envelope $envelope): Envelope
    {
        return $this->getSender()->send($envelope);
    }

    private function getSender(): KafkaSender
    {
        return $this->sender ?? $this->sender = new KafkaSender(
            $this->senderProperties,
            $this->serializer,
            $this->rdKafkaFactory,
            $this->logger
        );
    }

    private function getReceiver(): KafkaReceiver
    {
        return $this->receiver ?? $this->receiver = new KafkaReceiver(
            $this->receiverProperties,
            $this->serializer,
            $this->rdKafkaFactory,
            $this->logger
        );
    }
}
