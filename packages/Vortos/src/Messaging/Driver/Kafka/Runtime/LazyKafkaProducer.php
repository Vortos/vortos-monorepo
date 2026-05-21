<?php

declare(strict_types=1);

namespace Vortos\Messaging\Driver\Kafka\Runtime;

use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\Driver\Kafka\Factory\KafkaProducerFactory;

final class LazyKafkaProducer implements ProducerInterface
{
    private array $producers = [];

    public function __construct(
        private KafkaProducerFactory $factory,
    ) {}

    public function produce(string $transportName, object $payload, array $headers = []): void
    {
        $this->get($transportName)->produce($transportName, $payload, $headers);
    }

    public function produceBatch(string $transportName, array $payloads, array $headers = []): void
    {
        $this->get($transportName)->produceBatch($transportName, $payloads, $headers);
    }

    private function get(string $transportName): KafkaProducer
    {
        if (!isset($this->producers[$transportName])) {
            $this->producers[$transportName] = $this->factory->create($transportName);
        }
        return $this->producers[$transportName];
    }
}
