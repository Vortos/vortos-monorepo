<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag's targeting rules changed. Pure domain event payload (F1/F2/F3).
 *
 * Carries both sides of the change so the History UI (Block 24) can render a diff and
 * one-click revert without replaying the whole stream. Each side is a list of
 * {@see \Vortos\FeatureFlags\FlagRule::toArray()} arrays.
 */
final class FlagRulesChangedEvent
{
    /**
     * @param list<array<string,mixed>> $oldRules
     * @param list<array<string,mixed>> $newRules
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly array $oldRules,
        public readonly array $newRules,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
