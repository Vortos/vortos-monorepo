<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\MetricSource;

use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;

final class InMemoryGuardrailMetricSource implements GuardrailMetricSourceInterface
{
    /** @var array<string, float> keyed by "{metricKind}:{flagName}:{env}:{variant}" */
    private array $values = [];

    public function set(GuardrailMetricKind $kind, string $flagName, string $env, float $value, ?string $variant = null): void
    {
        $this->values[$kind->value . ':' . $flagName . ':' . $env . ':' . ($variant ?? '')] = $value;
    }

    public function query(GuardrailMetricQuery $query): ?float
    {
        $key = $query->metricKind->value . ':' . $query->flagName . ':' . $query->environment . ':' . ($query->variant ?? '');

        return $this->values[$key] ?? null;
    }
}
