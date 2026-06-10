<?php

declare(strict_types=1);

namespace Vortos\Messaging\Definition\Transport;

use Vortos\Foundation\Config\Env;

/**
 * Base class for all transport definitions.
 *
 * A transport definition is a value object that describes the "pipe" between
 * your application and a broker — the connection settings, topic/queue identity,
 * and serialization format. It holds no runtime state and performs no I/O.
 *
 * Every broker-specific transport (Kafka, RabbitMQ, SQS) extends this.
 * Users build these via fluent methods inside a MessagingConfig class.
 *
 * Any setting may be an Env reference (Env::string(), Env::int(), …) instead
 * of a literal — the compiler pass converts it to a '%env(...)%' placeholder
 * resolved by the container at runtime. Never read $_ENV in a config class:
 * definitions are evaluated at compile time, so an inline read would bake one
 * environment's value into the compiled container.
 */
abstract class AbstractTransportDefinition
{
    protected string $name;
    protected string|Env $dsn = '';
    protected string $serializer = 'json';

    protected function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * Named constructor. Use this instead of new — allows subclasses to be
     * created fluently without breaking the chain: KafkaTransportDefinition::create('name')->topic('...').
     */
    public static function create(string $name):static
    {
        return new static($name);
    }

    /** The registered name of this transport. Used as the lookup key in TransportRegistry. */
    public function getName(): string
    {
        return $this->name;
    }

    /** The broker connection string. Format is driver-specific (e.g. kafka://broker:9092). */
    public function dsn(string|Env $dsn): static
    {
        $this->dsn = $dsn;
        return $this;
    }

    /**
     * The wire format for serializing events on this transport.
     * Defaults to 'json'. Other values: 'avro', 'protobuf' (require matching SerializerInterface implementation).
     */
    public function serializer(string $serializer): static
    {
        $this->serializer = $serializer;
        return $this;
    }

    /** Returns normalized configuration array consumed by the runtime transport factory. */
    abstract public function toArray(): array;
}