<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * One or more definition-level fields of a flag were changed in a single edit
 * (description, kind, bucketBy, prerequisites, requiredScope, payload, defaultValue,
 * layer). Carries a `changes` map of field → new value so the audit log records exactly
 * what a bulk metadata edit touched, in one entry rather than one per field.
 */
final class FlagReconfiguredEvent
{
    /** @param array<string,mixed> $changes field name → new value (serializable) */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly array $changes,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
