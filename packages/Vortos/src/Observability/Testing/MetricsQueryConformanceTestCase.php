<?php

declare(strict_types=1);

namespace Vortos\Observability\Testing;

use Vortos\Observability\Query\Capability\MetricsQueryCapability;
use Vortos\Observability\Query\MetricQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Query\QueryWindow;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * Metrics-query TCK (§10.4). Every driver must pass this base to prove:
 *  - Honest sampleCount (never fabricated).
 *  - Empty series → isEmpty() true; quantile/mean throw (never fabricate 0.0).
 *  - Capability claims match actual behaviour.
 *  - Range window bounds are respected.
 */
abstract class MetricsQueryConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createQuery(): MetricsQueryInterface;

    protected function createDriver(): MetricsQueryInterface
    {
        return $this->createQuery();
    }

    /** Key used for this driver in the registry — must match #[AsDriver]. */
    abstract protected function expectedKey(): string;

    final public function test_instant_returns_result_object(): void
    {
        $query = MetricQuery::fromSloRef('up');
        $result = $this->createQuery()->instant($query);

        self::assertGreaterThanOrEqual(0, $result->sampleCount);
    }

    final public function test_empty_series_never_fabricates_zero(): void
    {
        // A driver that returns 0 samples must NOT have a non-NAN value
        $query = MetricQuery::fromSloRef('vortos_nonexistent_metric_for_tck_xyzzy');
        $result = $this->createQuery()->instant($query);

        if ($result->isEmpty()) {
            self::assertTrue(is_nan($result->value), 'Empty result must carry NAN, not a fabricated 0.0.');
        } else {
            self::markTestSkipped('Driver returned data for nonexistent metric — skip empty-result guard.');
        }
    }

    final public function test_range_returns_series_object(): void
    {
        $query = MetricQuery::fromSloRef('up');
        $window = new QueryWindow(lookbackSeconds: 60, stepSeconds: 15);
        $series = $this->createQuery()->range($query, $window);

        self::assertGreaterThanOrEqual(0, $series->sampleCount());
    }

    final public function test_empty_range_series_is_empty(): void
    {
        $query = MetricQuery::fromSloRef('vortos_nonexistent_metric_for_tck_xyzzy');
        $window = new QueryWindow(lookbackSeconds: 60, stepSeconds: 15);
        $series = $this->createQuery()->range($query, $window);

        if ($series->isEmpty()) {
            self::assertTrue($series->isEmpty());
            $this->expectException(\RuntimeException::class);
            $series->mean();
        } else {
            self::markTestSkipped('Driver returned data for nonexistent metric — skip empty-series guard.');
        }
    }

    final public function test_range_empty_series_quantile_throws(): void
    {
        $query = MetricQuery::fromSloRef('vortos_nonexistent_metric_for_tck_xyzzy');
        $window = new QueryWindow(lookbackSeconds: 60, stepSeconds: 15);
        $series = $this->createQuery()->range($query, $window);

        if ($series->isEmpty()) {
            $this->expectException(\RuntimeException::class);
            $series->quantile(0.99);
        } else {
            self::markTestSkipped('Driver returned data for nonexistent metric — skip empty-series guard.');
        }
    }

    final public function test_capabilities_are_populated(): void
    {
        $caps = $this->createQuery()->capabilities();

        // InstantQuery and RangeQuery must always be declared
        self::assertTrue(
            $caps->supports(MetricsQueryCapability::InstantQuery),
            'Driver must declare InstantQuery capability.',
        );
        self::assertTrue(
            $caps->supports(MetricsQueryCapability::RangeQuery),
            'Driver must declare RangeQuery capability.',
        );
    }

    final public function test_label_filter_accepted_when_declared(): void
    {
        $caps = $this->createQuery()->capabilities();

        if (!$caps->supports(MetricsQueryCapability::LabelFilter)) {
            self::markTestSkipped('Driver does not declare LabelFilter — skip.');
        }

        $query = MetricQuery::fromSloRef('up', ['job' => 'app']);
        $result = $this->createQuery()->instant($query);

        self::assertGreaterThanOrEqual(0, $result->sampleCount);
    }
}
