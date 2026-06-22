<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag was turned on. Pure domain event payload (F1/F2/F3).
 */
final class FlagEnabledEvent
{
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
