<?php

namespace Vortos\Domain\Identity;

use Stringable;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

/**
 * Abstract base class for typed aggregate identifiers.
 *
 * Wraps a UuidV7 string to provide type-safe identity across aggregates.
 * Two aggregates of different types can never have their IDs confused
 * at the PHP type system level — UserId and OrderId are distinct types
 * even though both wrap UUIDs.
 *
 * Always use generate() for new aggregates and fromString() when
 * reconstructing from persistence or deserializing from requests.
 * Never construct directly.
 *
 * Usage:
 *   final class UserId extends AggregateId {}
 *
 *   $id = UserId::generate();
 *   $same = UserId::fromString('019d...');
 *   $id->equals($same); // true or false
 */
abstract class AggregateId implements Stringable
{
    /**
     * Private constructor — always use generate() or fromString().
     */
    private function __construct(
        private readonly string $value
    ) {}

    /**
     * Generate a new time-sortable ID.
     * Uses UuidV7 — monotonically increasing, database-index friendly.
     */
    public static function generate(): static 
    {
        return new static(new UuidV7()->toRfc4122());
    }

    /**
     * Reconstruct from a string representation.
     * Use when loading from database or deserializing from a request.
     * 
     * @throws \InvalidArgumentException if the string is not a valid UUID
     */
    public static function fromString(string $id): static 
    {
        if(!Uuid::isValid($id)){
            throw new \InvalidArgumentException("Invalid UUID: {$id}");
        }
        
        return new static($id);
    }

    /**
     * Returns the raw UUID string.
     */
    public function toString(): string 
    {
        return $this->value;
    }

    /**
     * Allows casting to string: (string) $userId
     */
    public function __toString(): string 
    {
        return $this->value;
    }

    /**
     * Value equality — two IDs are equal if their values match.
     * Identity objects are NOT equal just because they are the same class.
     */
    public function equals(self $other): bool 
    {
        return $this->value === $other->value;
    }
}