<?php

namespace Vortos\Domain\Aggregate;

use Vortos\Domain\Event\DomainEventInterface;
use Vortos\Domain\Identity\AggregateId;

/**
 * Abstract base class for all domain aggregates (State-Based CQRS).
 *
 * Enforces the event recording pattern — state mutations and event recording
 * happen together in command methods. Events are collected internally and
 * dispatched by the ApplicationService after the Unit of Work commits.
 * Aggregates never dispatch events themselves.
 *
 * Provides optimistic concurrency control via a version integer.
 * The write repository increments the version on every successful save
 * and uses it in the WHERE clause to detect concurrent modifications.
 *
 * For Event Sourcing support see EventSourcedAggregateRoot (planned).
 *
 * Usage:
 *   final class User extends AggregateRoot
 *   {
 *       public static function register(string $email): self
 *       {
 *           $user = new self(UserId::generate(), $email);
 *           $user->recordEvent(new UserRegisteredEvent((string) $user->id, $email));
 *           return $user;
 *       }
 *   }
 */
abstract class AggregateRoot
{
    /**
     * Optimistic concurrency version.
     * Increments on every state change.
     * Write repository uses this to detect concurrent modifications.
     * 
     * @see DbalWriteRepository::save() — uses WHERE version = $currentVersion
     */
    private int $version = 0;

    /**
     * Domain events recorded during this command execution.
     * Never public — only accessible via pullDomainEvents().
     * 
     * @var DomainEventInterface[]
     */
    private array $domainEvents = [];

    /**
     * The aggregate's unique identity.
     * Must be a typed subclass of AggregateId.
     */
    abstract public function getId(): AggregateId;

    /**
     * Record a domain event without publishing it.
     * Call this inside command methods after mutating state.
     * 
     * Events are collected here and dispatched by the ApplicationService
     * AFTER the transaction commits — never inside the aggregate itself.
     */
    protected function recordEvent(DomainEventInterface $event): void 
    {
        $this->domainEvents[] = $event;
    }

    /**
     * Returns all recorded events and clears the internal collection.
     * Called by ApplicationService INSIDE the Unit of Work transaction —
     * events are written to the outbox within the same transaction as
     * the aggregate save. This guarantees atomicity between state change
     * and event publication.
     *
     * Calling this twice returns an empty array the second time.
     *
     * @return DomainEventInterface[]
     */
    public function pullDomainEvents(): array 
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];
        return $events; 
    }

    /**
     * Current version number for optimistic locking.
     * Read by DbalWriteRepository before issuing UPDATE.
     */
    public function getVersion(): int 
    {
        return $this->version;
    }

    /**
     * Increments version. Called by DbalWriteRepository after successful save.
     * Not called by user code directly.
     * 
     * @internal
     */
    public function incrementVersion(): void 
    {
        $this->version++;
    }

    /**
     * Checks whether any domain events have been recorded
     * since the last pullDomainEvents() call.
     */
    public function hasDomainEvents(): bool 
    {
        return !empty($this->domainEvents);
    }
}