<?php

declare(strict_types=1);

namespace Vortos\Analytics\Event;

/**
 * Identity traits to set/merge for a {@see DistinctId}. Bounded at construction
 * ({@see PropertyBounds}). Carries a deterministic content hash so
 * `Runtime\IdentityDedupeCache` can collapse repeated, identical calls.
 */
final readonly class IdentitySet
{
    public const MAX_TRAITS = 100;
    public const MAX_TRAIT_BYTES = 16384;

    public DistinctId $distinctId;

    /** @var array<string,mixed> */
    public array $traits;

    /** @param array<string,mixed> $traits */
    public function __construct(DistinctId $distinctId, array $traits = [])
    {
        $this->distinctId = $distinctId;
        $this->traits = PropertyBounds::bound($traits, self::MAX_TRAITS, self::MAX_TRAIT_BYTES);
    }

    /** Deterministic content hash for idempotency dedupe — never sent to a driver. */
    public function contentHash(): string
    {
        $encoded = json_encode($this->traits, JSON_THROW_ON_ERROR);

        return hash('sha256', $this->distinctId->value . '|' . $encoded);
    }
}
