<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

final class ChangeRequestExpiredEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly \DateTimeImmutable $expiredAt,
    ) {}
}
