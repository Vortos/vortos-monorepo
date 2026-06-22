<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag's variant weights changed. Pure domain event payload (F1/F2/F3).
 *
 * Old/new are the `variantName => weight` maps (or null when the flag is not
 * multivariate), enough to diff and revert.
 */
final class FlagVariantsChangedEvent
{
    /**
     * @param array<string,int>|null $oldVariants
     * @param array<string,int>|null $newVariants
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly ?array $oldVariants,
        public readonly ?array $newVariants,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
