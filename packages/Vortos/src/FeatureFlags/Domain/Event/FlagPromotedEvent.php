<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/** Flag env state was promoted (copied) from one environment to another. */
final class FlagPromotedEvent
{
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly string $fromEnvironment,
        public readonly string $toEnvironment,
        public readonly array $promotedState,
        public readonly string $actorId,
        public readonly ?string $reason = null,
    ) {}
}
