<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag's rollout schedule was set or cleared. Pure domain event payload
 * (F1/F2/F3). `schedule` is the {@see \Vortos\FeatureFlags\RolloutSchedule::toArray()}
 * snapshot, or null when the schedule was removed.
 */
final class FlagScheduledEvent
{
    /**
     * @param array<string,mixed>|null $schedule
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly ?array $schedule,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
