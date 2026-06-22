<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail\MetricSource;

use Vortos\FeatureFlags\Guardrail\GuardrailMetricQuery;

interface GuardrailMetricSourceInterface
{
    /**
     * Returns the current scalar metric value over the window, or null if unavailable.
     * null = unknown; NEVER triggers the guardrail.
     */
    public function query(GuardrailMetricQuery $query): ?float;
}
