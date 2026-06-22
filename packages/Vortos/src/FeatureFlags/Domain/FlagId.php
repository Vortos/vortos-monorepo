<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain;

use Vortos\Domain\Identity\AggregateId;

/**
 * Typed identity for the {@see Flag} aggregate.
 *
 * Wraps the flag's UUID (the `feature_flags.id` column — `string(36)`, RFC 4122)
 * so the aggregate, its events, and the ledger all key off a type-safe identifier
 * rather than a bare string.
 */
final class FlagId extends AggregateId
{
}
