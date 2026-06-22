<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

final class ChangeRequestCancelledEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly string $cancelledBy,
        public readonly \DateTimeImmutable $cancelledAt,
    ) {}
}
