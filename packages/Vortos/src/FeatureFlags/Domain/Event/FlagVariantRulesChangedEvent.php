<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * Per-variant targeting overrides changed — the rules that force a matching context
 * into a specific variant regardless of the weighted assignment. Old/new are
 * `variantName => FlagRule[]` maps (serialized) or null when there are no overrides.
 */
final class FlagVariantRulesChangedEvent
{
    /**
     * @param array<string,array<int,array<string,mixed>>>|null $oldVariantRules
     * @param array<string,array<int,array<string,mixed>>>|null $newVariantRules
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly ?array $oldVariantRules,
        public readonly ?array $newVariantRules,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
