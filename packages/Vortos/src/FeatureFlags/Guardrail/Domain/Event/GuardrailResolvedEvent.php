<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Domain\Event;

final readonly class GuardrailResolvedEvent
{
    public function __construct(
        public string $policyId,
        public string $flagName,
        public string $environment,
        public \DateTimeImmutable $at,
        /** null = auto-resolved by watcher; non-null = human-acknowledged */
        public ?string $acknowledgedBy = null,
    ) {}
}
