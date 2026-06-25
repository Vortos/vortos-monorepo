<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Query\QuerySample;
use Vortos\Observability\Query\QuerySeries;

final class QuerySeriesTest extends TestCase
{
    public function test_empty_series_is_empty(): void
    {
        self::assertTrue(QuerySeries::empty()->isEmpty());
        self::assertSame(0, QuerySeries::empty()->sampleCount());
    }

    public function test_non_empty_series(): void
    {
        $series = new QuerySeries([
            new QuerySample(1.0, 1000),
            new QuerySample(2.0, 1015),
        ]);

        self::assertFalse($series->isEmpty());
        self::assertSame(2, $series->sampleCount());
    }

    public function test_mean(): void
    {
        $series = new QuerySeries([
            new QuerySample(1.0, 1000),
            new QuerySample(3.0, 1015),
        ]);

        self::assertEqualsWithDelta(2.0, $series->mean(), 0.001);
    }

    public function test_mean_empty_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        QuerySeries::empty()->mean();
    }

    public function test_quantile_p50(): void
    {
        $series = new QuerySeries([
            new QuerySample(1.0, 1000),
            new QuerySample(2.0, 1015),
            new QuerySample(3.0, 1030),
            new QuerySample(4.0, 1045),
            new QuerySample(5.0, 1060),
        ]);

        $p50 = $series->quantile(0.5);
        self::assertGreaterThanOrEqual(2.0, $p50);
        self::assertLessThanOrEqual(4.0, $p50);
    }

    public function test_quantile_p99(): void
    {
        $samples = array_map(
            static fn (int $i): QuerySample => new QuerySample((float) $i, 1000 + $i),
            range(1, 100),
        );
        $series = new QuerySeries($samples);

        $p99 = $series->quantile(0.99);
        self::assertGreaterThanOrEqual(98.0, $p99);
        self::assertLessThanOrEqual(100.0, $p99);
    }

    public function test_quantile_p0_is_min(): void
    {
        $series = new QuerySeries([
            new QuerySample(5.0, 1000),
            new QuerySample(1.0, 1015),
            new QuerySample(3.0, 1030),
        ]);

        self::assertEqualsWithDelta(1.0, $series->quantile(0.0), 0.001);
    }

    public function test_quantile_p100_is_max(): void
    {
        $series = new QuerySeries([
            new QuerySample(5.0, 1000),
            new QuerySample(1.0, 1015),
            new QuerySample(3.0, 1030),
        ]);

        self::assertEqualsWithDelta(5.0, $series->quantile(1.0), 0.001);
    }

    public function test_quantile_empty_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        QuerySeries::empty()->quantile(0.99);
    }

    public function test_quantile_out_of_range_throws(): void
    {
        $series = new QuerySeries([new QuerySample(1.0, 1000)]);

        $this->expectException(\InvalidArgumentException::class);
        $series->quantile(1.5);
    }

    public function test_empty_series_never_fabricates_zero(): void
    {
        $series = QuerySeries::empty();

        self::assertTrue($series->isEmpty());
        // Verify mean() and quantile() throw — never return 0.0
        try {
            $series->mean();
            self::fail('Expected RuntimeException from mean() on empty series.');
        } catch (\RuntimeException) {
        }
    }
}
