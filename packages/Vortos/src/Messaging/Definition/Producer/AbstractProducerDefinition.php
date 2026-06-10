<?php

declare(strict_types=1);

namespace Vortos\Messaging\Definition\Producer;

/**
 * Base class for producer definitions.
 *
 * A producer definition is a value object that describes how events are sent
 * to a transport — whether the outbox pattern is used, the outbox table name,
 * and any default headers to attach to every message.
 *
 * Every broker-specific producer (Kafka, RabbitMQ) extends this.
 * Users build these via fluent methods inside a MessagingConfig class.
 */
abstract class AbstractProducerDefinition
{
    protected string $transportName;
    protected bool $outboxEnabled = true;
    protected array $publishedEvents = [];
    protected array $headers = [];

    protected function __construct(string $transportName)
    {
        $this->transportName = $transportName;
    }

    /** The registered name of this producer. Used as the lookup key in the registry. */
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
     * Configure the transactional outbox for this producer.
     * When enabled (default), events are written to the outbox table within the
     * domain transaction and relayed to the broker asynchronously by the OutboxRelayWorker.
     * Disable only when you explicitly need synchronous direct-to-broker production.
     */
    public function outbox(bool $enabled = true): static
    {
        $this->outboxEnabled = $enabled;
        return $this;
    }

    /**
     * Declares which domain event classes this producer routes to its transport.
     * Used by EventBus to resolve the correct producer at dispatch time.
     * Event classes must be final (pure POPOs — no base class required).
     * Validated by the compiler pass at container compile time.
     *
     * The wire name is derived by convention ({module}.{snake_case_class},
     * version 1) — see WireNaming. Use publish() to pin an explicit name or
     * bump the version.
     */
    public function publishes(string ...$eventClasses): static
    {
        foreach ($eventClasses as $eventClass) {
            $this->publishedEvents[$eventClass] = ['as' => null, 'version' => 1];
        }
        return $this;
    }

    /**
     * Declares a single published event with an explicit wire name and/or
     * schema version.
     *
     *   ->publish(EntryApproved::class, as: 'registration.entry_approved')
     *   ->publish(EntryApproved::class, version: 2)   // contract changed — add an upcaster
     *
     * Pin `as:` when renaming/moving the class so the wire name stays stable;
     * bump `version:` whenever the payload shape changes.
     */
    public function publish(string $eventClass, ?string $as = null, int $version = 1): static
    {
        $this->publishedEvents[$eventClass] = ['as' => $as, 'version' => $version];
        return $this;
    }

    /**
     * Default headers attached to every message produced by this producer.
     * Merged with headers passed at call time. Call-time headers take precedence.
     */
    public function headers(array $headers): static
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * Returns the list of event classes this producer is responsible for routing.
     *
     * @return list<class-string>
     */
    public function getPublishedEvents(): array
    {
        return array_keys($this->publishedEvents);
    }

    /**
     * Returns the full contract declarations: class → ['as' => ?string, 'version' => int].
     * Consumed by the compiler pass to build the wire name maps.
     *
     * @return array<class-string, array{as: ?string, version: int}>
     */
    public function getPublishedContracts(): array
    {
        return $this->publishedEvents;
    }

    /** Returns normalized configuration array consumed by the runtime producer factory. */
    abstract public function toArray(): array;
}
