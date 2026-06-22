<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\ChangeRequest\Domain\Event;

use Vortos\FeatureFlags\ChangeRequest\ChangeType;

final class ChangeRequestCreatedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $flagName,
        public readonly string $projectId,
        public readonly string $environment,
        public readonly ChangeType $changeType,
        public readonly array $payload,
        public readonly string $reason,
        public readonly string $requestedBy,
        public readonly \DateTimeImmutable $requestedAt,
        public readonly int $requiredApprovals,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly ?\DateTimeImmutable $applyAt = null,
    ) {}
}
