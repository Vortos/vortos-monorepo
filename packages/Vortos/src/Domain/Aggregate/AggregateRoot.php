<?php

declare(strict_types=1);

namespace Vortos\Domain\Aggregate;

use Symfony\Component\Uid\UuidV7;
use Vortos\Domain\Aggregate\Exception\InvalidEventPayloadException;
use Vortos\Domain\Event\DomainEventLedger;
use Vortos\Domain\Event\EventEnvelope;
use Vortos\Domain\Event\Metadata;
use Vortos\Domain\Identity\AggregateId;

/**
 * Abstract base class for all domain aggregates (State-Based CQRS).
 *
 * Enforces the event recording pattern — state mutations and event recording
 * happen together in command methods. Events are collected internally as
 * EventEnvelopes (the aggregate wraps each payload automatically) and also
 * registered with the DomainEventLedger, which the CommandBus/ConsumerRunner
 * drain inside the Unit of Work — dispatch never depends on the handler
 * returning the aggregate. Aggregates never dispatch events themselves.
 *
 * ## Event payload contract
 *
 * Anything passed to recordEvent() must be a pure data class:
 *   - declared `final`
 *   - all properties `public readonly` and constructor-promoted
 *   - no methods other than `__construct`
 *
 * These rules are checked lazily per payload class on first record and the
 * result cached for the lifetime of the process — zero overhead after the
 * first event per class. Violations throw InvalidEventPayloadException.
 *
 * ## Optimistic concurrency
 *
 * Provides optimistic concurrency control via a version integer. The write
 * repository increments the version on every successful save and uses it
 * in the WHERE clause to detect concurrent modifications. Each envelope
 * carries the post-save expected aggregateVersion ($this->getVersion() + 1).
 *
 * For Event Sourcing support see EventSourcedAggregateRoot (planned).
 *
 * Usage:
 *   final readonly class UserRegistered          // pure POPO — no base, no interface
 *   {
 *       public function __construct(
 *           public UserId $userId,
 *           public string $email,
 *       ) {}
 *   }
 *
 *   final class User extends AggregateRoot
 *   {
 *       public static function register(string $email): self
 *       {
 *           $user = new self(UserId::generate(), $email);
 *           $user->recordEvent(new UserRegistered($user->id, $email));
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
     * @see DbalStore::save() — uses WHERE lock_version = $currentVersion
     */
    private int $lockVersion = 0;

    /**
     * Tracks whether this aggregate has ever been persisted or reconstructed.
     * Set to true by restoreVersion() (reconstruction) and incrementVersion() (first save).
     * Allows repositories to distinguish INSERT from UPDATE without relying on version === 0.
     */
    private bool $persisted = false;

    /**
     * Domain events recorded during this command execution, wrapped in envelopes.
     * Never public — only accessible via pullDomainEvents().
     *
     * @var EventEnvelope[]
     */
    private array $domainEvents = [];

    /**
     * Per-process cache of payload classes that have passed shape validation.
     * Validation runs once per class per process; subsequent records skip
     * reflection entirely.
     *
     * @var array<class-string, true>
     */
    private static array $validatedPayloadShapes = [];

    /**
     * The aggregate's unique identity.
     * Must be a typed subclass of AggregateId.
     */
    abstract public function getId(): AggregateId;

    /**
     * Record a domain event without publishing it.
     *
     * Wraps the payload in an EventEnvelope with the aggregate's identity,
     * type, and post-save expected version. Metadata is empty at record time;
     * the EventBus enriches it during dispatch (correlation from tracer,
     * tenant from hooks).
     *
     * The payload must satisfy F1/F2/F3 — see class docblock and
     * InvalidEventPayloadException for details. Validation is lazy and cached.
     *
     * Call this inside command methods after mutating state. The envelope is
     * registered with the DomainEventLedger; the bus owning the transaction
     * drains the ledger and dispatches — never the aggregate itself, and
     * never dependent on the handler's return value.
     *
     * @throws InvalidEventPayloadException if payload class violates shape rules
     */
    protected function recordEvent(object $payload): void
    {
        $payloadClass = $payload::class;

        if (!isset(self::$validatedPayloadShapes[$payloadClass])) {
            self::assertValidPayloadShape($payloadClass);
            self::$validatedPayloadShapes[$payloadClass] = true;
        }

        $envelope = new EventEnvelope(
            eventId:          new UuidV7()->toRfc4122(),
            aggregateId:      (string) $this->getId(),
            aggregateType:    static::class,
            aggregateVersion: $this->getVersion() + 1,
            payloadType:      $payloadClass,
            schemaVersion:    1,
            occurredAt:       new \DateTimeImmutable(),
            payload:          $payload,
            metadata:         Metadata::empty(),
        );

        $this->domainEvents[] = $envelope;
        DomainEventLedger::instance()->record($envelope);
    }

    /**
     * Returns all recorded envelopes and clears the aggregate's local buffer.
     *
     * Inspection/testing API only. Dispatch is owned by the DomainEventLedger
     * drain in CommandBus/ConsumerRunner — do NOT pull events and dispatch
     * them manually; inside a managed dispatch the ledger has already
     * collected them and manual dispatch would double-publish.
     *
     * Calling this twice returns an empty array the second time. The ledger
     * is unaffected — this only clears the aggregate's own buffer.
     *
     * @return EventEnvelope[]
     */
    public function pullDomainEvents(): array
    {
        $envelopes = $this->domainEvents;
        $this->domainEvents = [];
        return $envelopes;
    }

    /**
     * Current version number for optimistic locking.
     * Read by DbalStore before issuing UPDATE.
     */
    public function getVersion(): int
    {
        return $this->lockVersion;
    }

    /**
     * Returns true if this aggregate has never been saved to or loaded from persistence.
     * Repositories use this to choose INSERT over UPDATE — more reliable than version === 0.
     *
     * Note: only reliable for DBAL-backed aggregates. ORM-backed aggregates are hydrated
     * by Doctrine directly via reflection and do not go through restoreVersion(), so this
     * flag is not set by Doctrine hydration. OrmStore uses $em->contains() instead.
     */
    public function isNew(): bool
    {
        return !$this->persisted;
    }

    /**
     * Restores version when reconstructing from persistence.
     * Only call from static reconstruct() factory methods.
     *
     * @internal
     */
    protected function restoreVersion(int $lockVersion): void
    {
        $this->lockVersion = $lockVersion;
        $this->persisted = true;
    }

    /**
     * Increments version. Called by DbalStore after successful save.
     * Not called by user code directly.
     *
     * @internal
     */
    public function incrementVersion(): void
    {
        $this->lockVersion++;
        $this->persisted = true;
    }

    /**
     * Checks whether any domain events have been recorded
     * since the last pullDomainEvents() call.
     */
    public function hasDomainEvents(): bool
    {
        return !empty($this->domainEvents);
    }

    /**
     * Validates F1/F2/F3 for an event payload class.
     *
     *   F1 — class must be `final`
     *   F2 — own properties must be `public readonly` and constructor-promoted
     *   F3 — class must have no methods other than `__construct` (own OR inherited)
     *
     * F3 catches inheritance accidents (e.g. extending a base class that adds methods)
     * because inherited methods appear in getMethods(). F2 only inspects
     * properties declared on the payload class itself.
     *
     * @throws InvalidEventPayloadException
     */
    private static function assertValidPayloadShape(string $payloadClass): void
    {
        $refl = new \ReflectionClass($payloadClass);

        // F1
        if (!$refl->isFinal()) {
            throw InvalidEventPayloadException::notFinal($payloadClass);
        }

        // F3 — any method other than __construct (own or inherited) is a violation
        foreach ($refl->getMethods() as $method) {
            if ($method->getName() === '__construct') {
                continue;
            }
            throw InvalidEventPayloadException::hasMethod(
                $payloadClass,
                $method->getName(),
                $method->getDeclaringClass()->getName(),
            );
        }

        // F2 — own properties only
        foreach ($refl->getProperties() as $prop) {
            if ($prop->getDeclaringClass()->getName() !== $payloadClass) {
                continue;
            }
            if (!$prop->isPublic()) {
                throw InvalidEventPayloadException::badProperty(
                    $payloadClass,
                    $prop->getName(),
                    'property is not public',
                );
            }
            if (!$prop->isReadOnly()) {
                throw InvalidEventPayloadException::badProperty(
                    $payloadClass,
                    $prop->getName(),
                    'property is not readonly',
                );
            }
            if (!$prop->isPromoted()) {
                throw InvalidEventPayloadException::badProperty(
                    $payloadClass,
                    $prop->getName(),
                    'property is not constructor-promoted',
                );
            }
        }
    }
}
