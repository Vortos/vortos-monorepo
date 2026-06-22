<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

final readonly class GuardrailMetricQuery
{
    public function __construct(
        public GuardrailMetricKind $metricKind,
        public string $flagName,
        public string $environment,
        public int $windowSeconds,
        public ?string $customMetricName = null,
    ) {}
}
