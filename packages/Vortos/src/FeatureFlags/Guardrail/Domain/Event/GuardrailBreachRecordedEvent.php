<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Domain\Event;

final readonly class GuardrailBreachRecordedEvent
{
    public function __construct(
        public string $policyId,
        public string $flagName,
        public string $environment,
        public int $consecutiveBreachCount,
        public int $requiredWindows,
        public \DateTimeImmutable $at,
    ) {}
}
