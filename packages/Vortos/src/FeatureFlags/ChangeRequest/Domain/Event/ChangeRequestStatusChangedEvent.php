<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

use Vortos\FeatureFlags\ChangeRequest\ChangeRequestStatus;

final class ChangeRequestStatusChangedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly ChangeRequestStatus $from,
        public readonly ChangeRequestStatus $to,
        public readonly \DateTimeImmutable $at,
    ) {}
}
