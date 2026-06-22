<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

use Vortos\FeatureFlags\FlagLifecycleState;

/** Lifecycle state of a flag changed (Draft ↔ Active → Archived). */
final class FlagLifecycleChangedEvent
{
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly FlagLifecycleState $from,
        public readonly FlagLifecycleState $to,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
