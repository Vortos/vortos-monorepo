<?php

declare(strict_types=1);

namespace Vortos\Messaging\Definition\Consumer;

use Vortos\Messaging\Retry\RetryPolicy;

/**
 * Base class for consumer definitions.
 *
 * A consumer definition is a value object that describes how a worker fleet
 * processes messages from a transport — parallelism, batch size, retry policy,
 * and dead-letter routing. It holds no runtime state and performs no I/O.
 *
 * Every broker-specific consumer (Kafka, RabbitMQ) extends this.
 * Users build these via fluent methods inside a MessagingConfig class.
 */
abstract class AbstractConsumerDefinition
{
    protected string $transportName;
    protected int $parallelism = 1;
    protected int $batchSize = 1;
    protected bool $asyncCommit = true;
    protected ?RetryPolicy $retryPolicy = null;
    protected string $dlqTransport = '';
    protected ?int $idempotencyTtl = null;

    /** @var array<string, class-string> wire name → local contract class */
    protected array $contracts = [];

    /** @var array<string, array<int, class-string>> wire name → [fromVersion => upcaster class] */
    protected array $upcasters = [];

    protected function __construct(string $transportName)
    {
        $this->transportName = $transportName;
    }

    /** The registered name of this consumer. Used as the lookup key in the registry. */
    public function getName():string
    {
        return $this->transportName;
    }

    /** Named constructor. Always use this instead of new. */
    public static function create(string $transportName):static
    {
        return new static($transportName);
    }

    /**
     * How many messages one worker process handles concurrently.
     * For CPU-bound handlers keep this at 1. For I/O-bound handlers increase carefully.
     * This is message-level parallelism within a single process, not process count.
     */
    public function parallelism(int $count= 1):static
    {
        $this->parallelism = $count;
        return $this;
    }

    /**
     * Whether to commit offsets asynchronously (default: true).
     * Async is recommended for high-throughput consumers — it does not block
     * waiting for broker acknowledgement. Set to false only when you need
     * guaranteed offset commits before processing the next message.
     */
    public function commitMode(bool $async = true):static
    {
        $this->asyncCommit = $async;
        return $this;
    }

    /**
     * How many messages are fetched and processed together before committing offset.
     * Higher values improve throughput but increase reprocessing window on failure.
     */
    public function batchSize(int $size= 1):static
    {
        $this->batchSize = $size;
        return $this;
    }

    /**
     * Configures the retry policy for this consumer.
     * Example: RetryPolicy::exponential(attempts: 3, initialDelayMs: 500)
     */
    public function retry(RetryPolicy $policy):static
    {
        $this->retryPolicy = $policy;
        return $this;
    }
 
    public function getRetryPolicy():?RetryPolicy
    {
        return $this->retryPolicy;
    }

    /**
     * Dedup window for idempotency keys in seconds.
     * Overrides the global consumer_defaults.idempotency_ttl for this consumer only.
     * Null means use the global default.
     */
    public function idempotencyTtl(int $seconds): static
    {
        $this->idempotencyTtl = $seconds;
        return $this;
    }

    /**
     * Name of the transport to use as the dead-letter destination.
     * Messages that exhaust all retry attempts are routed here.
     * Must reference a registered transport name.
     */
    public function dlq(string $dlqTransportName):static
    {
        $this->dlqTransport = $dlqTransportName;
        return $this;
    }

    /**
     * Maps a logical wire event name to THIS consumer's local contract class.
     *
     *   ->handles('registration.entry_approved', EntryApproved::class)
     *   // EntryApproved here is the consuming module's OWN class — never an
     *   // import from the producing module's domain.
     *
     * Usually unnecessary: handlers declare the mapping via
     * #[AsEventHandler(event: '...')] and the parameter type. Use this for
     * handler-less consumers (projections) or to override.
     */
    public function handles(string $wireName, string $localClass): static
    {
        $this->contracts[$wireName] = $localClass;
        return $this;
    }

    /**
     * Explicit wire-name → local class mappings declared on this consumer.
     *
     * @return array<string, class-string>
     */
    public function getContracts(): array
    {
        return $this->contracts;
    }

    /**
     * Registers an upcaster transforming this wire event's payload from one
     * schema version to the next, applied before hydration:
     *
     *   ->upcast('registration.entry_approved', from: 1, to: 2, upcaster: EntryApprovedV1ToV2::class)
     *
     * Old messages already in topics/outbox/DLQ keep working after a contract
     * version bump — the chain lifts them to the shape this consumer speaks.
     */
    public function upcast(string $wireName, int $from, int $to, string $upcaster): static
    {
        if ($to !== $from + 1) {
            throw new \LogicException(
                "Upcaster for '{$wireName}' must advance exactly one version (from {$from} to " . ($from + 1) . "), got to: {$to}. Chain single steps instead."
            );
        }

        $this->upcasters[$wireName][$from] = $upcaster;
        return $this;
    }

    /**
     * Registered upcasters: wireName → [fromVersion => upcaster class].
     *
     * @return array<string, array<int, class-string>>
     */
    public function getUpcasters(): array
    {
        return $this->upcasters;
    }

    /** Returns normalized configuration array consumed by the runtime consumer factory. */
    abstract public function toArray(): array;
}