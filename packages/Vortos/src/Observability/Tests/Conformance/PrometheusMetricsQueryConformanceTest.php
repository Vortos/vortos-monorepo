<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Query\Driver\PrometheusMetricsQuery;
use Vortos\Observability\Query\MetricQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Query\QueryWindow;
use Vortos\Observability\Testing\MetricsQueryConformanceTestCase;

final class PrometheusMetricsQueryConformanceTest extends MetricsQueryConformanceTestCase
{
    private const INSTANT_FIXTURE = '{"status":"success","data":{"resultType":"vector","result":[{"metric":{"job":"app"},"value":[1700000000,"0.023"]}]}}';
    private const RANGE_FIXTURE = '{"status":"success","data":{"resultType":"matrix","result":[{"metric":{"job":"app"},"values":[[1700000000,"0.023"],[1700000015,"0.025"],[1700000030,"0.021"]]}]}}';
    private const EMPTY_FIXTURE = '{"status":"success","data":{"resultType":"vector","result":[]}}';

    protected function createQuery(): MetricsQueryInterface
    {
        return $this->makeQuery();
    }

    protected function expectedKey(): string
    {
        return 'slo-prometheus';
    }

    private function makeQuery(string $fixture = self::INSTANT_FIXTURE): PrometheusMetricsQuery
    {
        return new PrometheusMetricsQuery(
            baseUrl: 'https://prometheus.example.com',
            bearerToken: '',
            timeoutSeconds: 5,
            transport: static fn (string $url): string => str_contains($url, 'query_range')
                ? self::RANGE_FIXTURE
                : $fixture,
        );
    }

    public function test_instant_returns_populated_result(): void
    {
        $query = $this->makeQuery(self::INSTANT_FIXTURE);
        $result = $query->instant(MetricQuery::fromSloRef('up', ['job' => 'app']));

        self::assertFalse($result->isEmpty());
        self::assertSame(1, $result->sampleCount);
        self::assertEqualsWithDelta(0.023, $result->value, 0.001);
    }

    public function test_range_returns_populated_series(): void
    {
        $query = $this->makeQuery();
        $series = $query->range(
            MetricQuery::fromSloRef('up', ['job' => 'app']),
            new QueryWindow(lookbackSeconds: 60, stepSeconds: 15),
        );

        self::assertFalse($series->isEmpty());
        self::assertSame(3, $series->sampleCount());
    }

    public function test_empty_backend_response_gives_empty_result(): void
    {
        $query = $this->makeQuery(self::EMPTY_FIXTURE);
        $result = $query->instant(MetricQuery::fromSloRef('nonexistent_metric'));

        self::assertTrue($result->isEmpty());
        self::assertTrue(is_nan($result->value));
    }

    public function test_http_error_gives_empty_result_not_exception(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://prometheus.example.com',
            transport: static function (): never {
                throw new \RuntimeException('connection refused');
            },
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));

        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_http_scheme_rejected_at_construction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('HTTPS');

        new PrometheusMetricsQuery(
            baseUrl: 'http://prometheus.example.com',
            transport: static fn (): string => '',
        );
    }

    public function test_ssrf_unresolved_host_fails_closed(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://unresolvable.example.invalid',
            resolver: static fn (string $host): array => [],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));
        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_private_ip_blocked_at_request_time(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://rebinding.example.com',
            resolver: static fn (string $host): array => ['10.0.0.1'],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));
        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_metadata_ip_blocked(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://evil.example.com',
            resolver: static fn (string $host): array => ['169.254.169.254'],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));
        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_loopback_blocked(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://evil.example.com',
            resolver: static fn (string $host): array => ['127.0.0.1'],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));
        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_ipv6_ula_blocked(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://evil.example.com',
            resolver: static fn (string $host): array => ['fc00::1'],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up'));
        self::assertTrue($result->isEmpty());
    }

    public function test_ssrf_direct_ip_literal_validated(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PrometheusMetricsQuery(
            baseUrl: 'http://169.254.169.254/latest/meta-data/',
        );
    }

    public function test_ssrf_public_ip_with_resolver_succeeds(): void
    {
        $query = new PrometheusMetricsQuery(
            baseUrl: 'https://prometheus.example.com',
            transport: static fn (string $url): string => self::INSTANT_FIXTURE,
            resolver: static fn (string $host): array => ['93.184.216.34'],
        );

        $result = $query->instant(MetricQuery::fromSloRef('up', ['job' => 'app']));
        self::assertFalse($result->isEmpty());
    }
}
