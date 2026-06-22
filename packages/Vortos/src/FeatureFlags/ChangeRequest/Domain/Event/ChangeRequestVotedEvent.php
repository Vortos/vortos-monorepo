<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

final class ChangeRequestVotedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly string $actorId,
        public readonly bool $approved,
        public readonly string $reason,
        public readonly \DateTimeImmutable $at,
    ) {}
}
