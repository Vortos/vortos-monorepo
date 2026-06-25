<?php

declare(strict_types=1);

namespace Vortos\Observability\Query\Driver;

use Vortos\Observability\Query\Capability\MetricsQueryCapability;
use Vortos\Observability\Query\MetricQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Query\QueryResult;
use Vortos\Observability\Query\QuerySeries;
use Vortos\Observability\Query\QueryWindow;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * No-op metrics query backend. Returns empty results.
 *
 * Valid for non-canary strategies and local dev. The canary doctor check
 * (CanaryAnalyzerReadyCheck) refuses this backend on strategy('canary').
 *
 * The "empty series never fabricates 0.0" invariant is satisfied: sampleCount=0
 * for every result.
 */
#[AsDriver('null')]
final class NullMetricsQuery implements MetricsQueryInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            MetricsQueryCapability::InstantQuery->value => true,
            MetricsQueryCapability::RangeQuery->value => true,
            MetricsQueryCapability::Quantiles->value => false,
            MetricsQueryCapability::LabelFilter->value => false,
        ]);
    }

    public function instant(MetricQuery $q): QueryResult
    {
        return QueryResult::empty(new \DateTimeImmutable());
    }

    public function range(MetricQuery $q, QueryWindow $w): QuerySeries
    {
        return QuerySeries::empty();
    }
}
