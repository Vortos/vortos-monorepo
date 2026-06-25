<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Canary;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Canary\CanaryAnalysisRequest;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryInstantResult;
use Vortos\Deploy\Canary\CanaryMetricSpec;
use Vortos\Deploy\Canary\CanaryMetricsPort;
use Vortos\Deploy\Canary\CanarySloRef;
use Vortos\Deploy\Canary\CanaryWindow;
use Vortos\Deploy\Canary\Driver\SloPrometheusCanaryAnalyzer;
use Vortos\Deploy\Target\ActiveColor;

final class SloPrometheusCanaryAnalyzerTest extends TestCase
{
    private CanarySloRef $slo;
    private CanaryWindow $window;

    protected function setUp(): void
    {
        $this->slo = new CanarySloRef('error-rate', 'http_errors_total', 0.99);
        $this->window = new CanaryWindow(300, 15, 5, 3, 600);
    }

    private function fakePort(float $stagedValue, float $stableValue, int $samples = 10): CanaryMetricsPort
    {
        return new class($stagedValue, $stableValue, $samples) implements CanaryMetricsPort {
            public function __construct(
                private float $staged,
                private float $stable,
                private int $samples,
            ) {}

            public function instant(string $indicatorRef, string $color): CanaryInstantResult
            {
                return match ($color) {
                    'blue' => new CanaryInstantResult($this->staged, $this->samples),
                    'green' => new CanaryInstantResult($this->stable, $this->samples),
                    default => new CanaryInstantResult($this->staged, $this->samples),
                };
            }
        };
    }

    private function emptyPort(): CanaryMetricsPort
    {
        return new class implements CanaryMetricsPort {
            public function instant(string $indicatorRef, string $color): CanaryInstantResult
            {
                return CanaryInstantResult::empty();
            }
        };
    }

    private function errorPort(): CanaryMetricsPort
    {
        return new class implements CanaryMetricsPort {
            public function instant(string $indicatorRef, string $color): CanaryInstantResult
            {
                throw new \RuntimeException('connection refused');
            }
        };
    }

    private function makeRequest(): CanaryAnalysisRequest
    {
        return new CanaryAnalysisRequest(
            env: 'production',
            staged: ActiveColor::Blue,
            stable: ActiveColor::Green,
            weight: 5,
            specs: [CanaryMetricSpec::errorRate($this->slo, 0.10)],
            window: $this->window,
            buildId: 'build-123',
            at: new \DateTimeImmutable(),
        );
    }

    public function test_healthy_staged_vs_stable_returns_progress(): void
    {
        $a = new SloPrometheusCanaryAnalyzer($this->fakePort(0.01, 0.01));
        $v = $a->analyze($this->makeRequest());

        self::assertSame(CanaryDecision::Progress, $v->decision);
        self::assertNotEmpty($v->evaluations);
        self::assertFalse($v->evaluations[0]->breached);
    }

    public function test_breached_staged_returns_rollback_with_evidence(): void
    {
        // staged=0.50 >> stable=0.01 × 1.10 = 0.011 — breach
        $a = new SloPrometheusCanaryAnalyzer($this->fakePort(0.50, 0.01));
        $v = $a->analyze($this->makeRequest());

        self::assertSame(CanaryDecision::Rollback, $v->decision);
        self::assertTrue($v->evaluations[0]->breached);
        self::assertStringContainsString('breach', strtolower($v->reason));
    }

    public function test_query_timeout_returns_inconclusive_not_progress(): void
    {
        $a = new SloPrometheusCanaryAnalyzer($this->errorPort());
        $v = $a->analyze($this->makeRequest());

        self::assertSame(CanaryDecision::Inconclusive, $v->decision);
    }

    public function test_empty_series_returns_inconclusive(): void
    {
        $a = new SloPrometheusCanaryAnalyzer($this->emptyPort());
        $v = $a->analyze($this->makeRequest());

        self::assertSame(CanaryDecision::Inconclusive, $v->decision);
    }

    public function test_multi_metric_one_breaches_returns_rollback(): void
    {
        $slo2 = new CanarySloRef('latency-p99', 'http_latency_p99', 0.99);

        $port = new class implements CanaryMetricsPort {
            public function instant(string $indicatorRef, string $color): CanaryInstantResult
            {
                if (str_contains($indicatorRef, 'latency')) {
                    return new CanaryInstantResult(0.05, 10);
                }
                // error rate breached for staged (blue)
                return new CanaryInstantResult($color === 'blue' ? 0.5 : 0.01, 10);
            }
        };

        $request = new CanaryAnalysisRequest(
            env: 'production',
            staged: ActiveColor::Blue,
            stable: ActiveColor::Green,
            weight: 5,
            specs: [
                CanaryMetricSpec::errorRate($this->slo, 0.10),
                CanaryMetricSpec::latencyP99($slo2, 0.15),
            ],
            window: $this->window,
            buildId: 'build-123',
            at: new \DateTimeImmutable(),
        );

        $a = new SloPrometheusCanaryAnalyzer($port);
        $v = $a->analyze($request);

        self::assertSame(CanaryDecision::Rollback, $v->decision);
        $breached = array_filter($v->evaluations, static fn ($e) => $e->breached);
        self::assertNotEmpty($breached);
    }

    public function test_verdict_does_not_contain_secret(): void
    {
        $a = new SloPrometheusCanaryAnalyzer($this->fakePort(0.01, 0.01));
        $v = $a->analyze($this->makeRequest());

        $json = json_encode($v->toArray());
        self::assertStringNotContainsString('bearer', strtolower($json));
        self::assertStringNotContainsString('password', strtolower($json));
        self::assertStringNotContainsString('secret', strtolower($json));
    }
}
