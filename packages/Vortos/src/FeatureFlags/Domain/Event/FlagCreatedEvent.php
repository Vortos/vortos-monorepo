<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag was created.
 *
 * Pure domain event payload (F1/F2/F3 — final, all props public readonly promoted,
 * no methods but the constructor). Recorded by {@see \Vortos\FeatureFlags\Domain\Flag}
 * and drained into the ledger by the owning bus.
 *
 * `state` is the full {@see \Vortos\FeatureFlags\FeatureFlag::toArray()} snapshot at
 * creation — the baseline the History/revert read model (Block 24) rebuilds from.
 * `actorId` / `reason` make every mutation answer "who" and "why" without a separate
 * lookup (the foundation 4-eyes (Block 14) and webhooks (Block 18) inherit for free).
 */
final class FlagCreatedEvent
{
    /**
     * @param array<string,mixed> $state full flag snapshot (FeatureFlag::toArray())
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly array $state,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
