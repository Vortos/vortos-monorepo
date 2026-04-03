<?php

namespace Vortos\Domain\ValueObject;

/**
 * Abstract base class for domain value objects.
 *
 * Value objects have no identity — they are equal if their values are equal.
 * Two Email('a@example.com') instances are the same value object.
 * Subclasses must implement equals() with domain-specific equality rules.
 *
 * Value objects are always immutable. Use readonly properties.
 * Never add setters. Return new instances for transformations.
 *
 * Usage:
 *   final readonly class Email extends ValueObject
 *   {
 *       private function __construct(private string $value) {}
 *
 *       public static function fromString(string $email): self { ... }
 *
 *       public function equals(self $other): bool
 *       {
 *           return $this->value === $other->value;
 *       }
 *
 *       public function __toString(): string { return $this->value; }
 *   }
 */
abstract readonly class ValueObject
{
    /**
     * Value equality — two value objects are equal if all their
     * properties have the same values.
     * 
     * Uses strict comparison. Subclasses may override for
     * domain-specific equality rules (e.g. case-insensitive email).
     */
    abstract public function equals(self $other): bool;

    /**
     * Human-readable representation for logging and debugging.
     * Subclasses should override with meaningful output.
     */
    abstract public function __toString(): string;
}