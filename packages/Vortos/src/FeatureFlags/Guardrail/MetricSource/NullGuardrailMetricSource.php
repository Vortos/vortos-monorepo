<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\MetricSource;

use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;

final class NullGuardrailMetricSource implements GuardrailMetricSourceInterface
{
    public function query(GuardrailMetricQuery $query): ?float
    {
        return null;
    }
}
