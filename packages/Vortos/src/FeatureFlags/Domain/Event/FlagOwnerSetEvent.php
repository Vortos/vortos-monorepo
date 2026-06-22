<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/** Owner (team / email / squad identifier) was set or changed on a flag. */
final class FlagOwnerSetEvent
{
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly ?string $previousOwner,
        public readonly ?string $newOwner,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
