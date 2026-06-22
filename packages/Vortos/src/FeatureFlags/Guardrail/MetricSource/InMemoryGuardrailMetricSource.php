<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\MetricSource;

use Vortos\FeatureFlags\Guardrail\GuardrailMetricKind;
use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;

final class InMemoryGuardrailMetricSource implements GuardrailMetricSourceInterface
{
    /** @var array<string, float> keyed by "{metricKind}:{flagName}:{env}" */
    private array $values = [];

    public function set(GuardrailMetricKind $kind, string $flagName, string $env, float $value): void
    {
        $this->values[$kind->value . ':' . $flagName . ':' . $env] = $value;
    }

    public function query(GuardrailMetricQuery $query): ?float
    {
        $key = $query->metricKind->value . ':' . $query->flagName . ':' . $query->environment;

        return $this->values[$key] ?? null;
    }
}
