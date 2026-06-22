<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\Domain\Event;

final readonly class GuardrailTriggeredEvent
{
    public function __construct(
        public string $policyId,
        public string $flagName,
        public string $environment,
        public string $action,
        public float $observedValue,
        public \DateTimeImmutable $at,
    ) {}
}
