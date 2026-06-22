<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/** Expiry date was set or cleared on a flag. */
final class FlagExpirySetEvent
{
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly ?\DateTimeImmutable $previousExpiry,
        public readonly ?\DateTimeImmutable $newExpiry,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
