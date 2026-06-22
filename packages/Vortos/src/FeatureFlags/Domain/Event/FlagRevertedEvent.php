<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag was reverted to a prior state. Pure domain event payload (F1/F2/F3).
 *
 * Revert is itself an auditable mutation — never a silent state change. Both the state
 * we moved away from and the state we restored are recorded for a complete trail.
 */
final class FlagRevertedEvent
{
    /**
     * @param array<string,mixed> $fromState state immediately before the revert
     * @param array<string,mixed> $toState   state restored by the revert
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly array $fromState,
        public readonly array $toState,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
