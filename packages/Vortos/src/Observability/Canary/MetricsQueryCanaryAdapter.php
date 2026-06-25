<?php

declare(strict_types=1);

namespace Vortos\Observability\Canary;

use Vortos\Deploy\Canary\CanaryInstantResult;
use Vortos\Deploy\Canary\CanaryMetricsPort;
use Vortos\Observability\Query\MetricQuery;
use Vortos\Observability\Query\MetricsQueryInterface;

/**
 * Bridges Deploy's CanaryMetricsPort seam to the Observability MetricsQueryInterface.
 * Fail-closed: any exception → empty result.
 */
final class MetricsQueryCanaryAdapter implements CanaryMetricsPort
{
    public function __construct(
        private readonly MetricsQueryInterface $metricsQuery,
    ) {}

    public function instant(string $indicatorRef, string $color): CanaryInstantResult
    {
        try {
            $q = MetricQuery::fromSloRef($indicatorRef, ['color' => $color]);
            $result = $this->metricsQuery->instant($q);

            if ($result->isEmpty()) {
                return CanaryInstantResult::empty();
            }

            return new CanaryInstantResult($result->value, $result->sampleCount);
        } catch (\Throwable) {
            return CanaryInstantResult::empty();
        }
    }
}
