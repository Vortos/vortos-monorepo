<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

use Vortos\FeatureFlags\ChangeRequest\ChangeType;

final class ChangeRequestAppliedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly string $projectId,
        public readonly string $environment,
        public readonly ChangeType $changeType,
        public readonly string $appliedBy,
        public readonly \DateTimeImmutable $appliedAt,
    ) {}
}
