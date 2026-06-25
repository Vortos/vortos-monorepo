<?php

declare(strict_types=1);

namespace Vortos\Analytics\Event;

use InvalidArgumentException;

/**
 * Associates a distinctId with a group (org/team/account). Bounded at construction
 * ({@see PropertyBounds}). Carries a deterministic content hash so
 * `Runtime\IdentityDedupeCache` can collapse repeated, identical calls.
 */
final readonly class GroupAssociation
{
    public const MAX_TRAITS = 100;
    public const MAX_TRAIT_BYTES = 16384;

    public DistinctId $distinctId;
    public string $groupType;
    public string $groupKey;

    /** @var array<string,mixed> */
    public array $traits;

    /** @param array<string,mixed> $traits */
    public function __construct(DistinctId $distinctId, string $groupType, string $groupKey, array $traits = [])
    {
        if ($groupType === '') {
            throw new InvalidArgumentException('GroupAssociation groupType must not be empty.');
        }
        if ($groupKey === '') {
            throw new InvalidArgumentException('GroupAssociation groupKey must not be empty.');
        }

        $this->distinctId = $distinctId;
        $this->groupType = $groupType;
        $this->groupKey = $groupKey;
        $this->traits = PropertyBounds::bound($traits, self::MAX_TRAITS, self::MAX_TRAIT_BYTES);
    }

    /** Deterministic content hash for idempotency dedupe — never sent to a driver. */
    public function contentHash(): string
    {
        $encoded = json_encode($this->traits, JSON_THROW_ON_ERROR);

        return hash('sha256', $this->distinctId->value . '|' . $this->groupType . '|' . $this->groupKey . '|' . $encoded);
    }
}
