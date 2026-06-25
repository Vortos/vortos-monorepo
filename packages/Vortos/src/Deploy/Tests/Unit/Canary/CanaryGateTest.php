<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Unit\Canary;

use PHPUnit\Framework\TestCase;
use Vortos\Deploy\Canary\CanaryAnalysisRequest;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryComparator;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryGate;
use Vortos\Deploy\Canary\CanaryMetricSpec;
use Vortos\Deploy\Canary\CanarySloRef;
use Vortos\Deploy\Canary\CanaryVerdict;
use Vortos\Deploy\Canary\CanaryWindow;
use Vortos\Deploy\Canary\MetricEvaluation;
use Vortos\Deploy\Canary\StatisticalGuard;
use Vortos\Deploy\Target\ActiveColor;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

final class CanaryGateTest extends TestCase
{
    private CanarySloRef $slo;
    private CanaryWindow $window;

    protected function setUp(): void
    {
        $this->slo = new CanarySloRef('error-rate', 'http_errors_total', 0.99);
        $this->window = new CanaryWindow(300, 15, 5, 3, 600);
    }

    private function makeRequest(int $weight = 5, \DateTimeImmutable $at = null): CanaryAnalysisRequest
    {
        return new CanaryAnalysisRequest(
            env: 'production',
            staged: ActiveColor::Blue,
            stable: ActiveColor::Green,
            weight: $weight,
            specs: [CanaryMetricSpec::errorRate($this->slo, 0.10)],
            window: $this->window,
            buildId: 'build-123',
            at: $at ?? new \DateTimeImmutable(),
        );
    }

    private function analyzerReturning(CanaryDecision $decision, bool $breached = false): CanaryAnalyzerInterface
    {
        $eval = new MetricEvaluation(
            sloName: 'error-rate',
            comparator: CanaryComparator::RelativeToBaseline,
            stagedValue: 0.01,
            stableValue: 0.01,
            breached: $breached,
            reason: $decision->name,
        );

        return new class($decision, $eval) implements CanaryAnalyzerInterface {
            public function __construct(
                private CanaryDecision $d,
                private MetricEvaluation $eval,
            ) {}

            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }

            public function analyze(CanaryAnalysisRequest $r): CanaryVerdict
            {
                return new CanaryVerdict($this->d, [$this->eval], $this->d->name, 10, $r->at);
            }
        };
    }

    public function test_healthy_stream_returns_progress(): void
    {
        $gate = new CanaryGate($this->analyzerReturning(CanaryDecision::Progress), new StatisticalGuard());
        $v = $gate->gate($this->makeRequest());

        self::assertSame(CanaryDecision::Progress, $v->decision);
    }

    public function test_sustained_breach_triggers_rollback(): void
    {
        $gate = new CanaryGate($this->analyzerReturning(CanaryDecision::Rollback, true), new StatisticalGuard());

        // Need to accumulate breachIntervals=3 consecutive breaches
        $v1 = $gate->gate($this->makeRequest());
        $v2 = $gate->gate($this->makeRequest());
        $v3 = $gate->gate($this->makeRequest());

        self::assertSame(CanaryDecision::Rollback, $v3->decision);
    }

    public function test_single_blip_not_rollback(): void
    {
        // One breach, then healthy — should NOT roll back
        $blipAnalyzer = new class implements CanaryAnalyzerInterface {
            private int $call = 0;

            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }

            public function analyze(CanaryAnalysisRequest $r): CanaryVerdict
            {
                $this->call++;
                $breached = $this->call === 1; // only first call is a breach

                $eval = new MetricEvaluation(
                    sloName: 'error-rate',
                    comparator: CanaryComparator::RelativeToBaseline,
                    stagedValue: $breached ? 0.5 : 0.01,
                    stableValue: 0.01,
                    breached: $breached,
                    reason: $breached ? 'breach' : 'ok',
                );

                return new CanaryVerdict(
                    $breached ? CanaryDecision::Hold : CanaryDecision::Progress,
                    [$eval],
                    $breached ? 'breach' : 'ok',
                    10,
                    $r->at,
                );
            }
        };

        $gate = new CanaryGate($blipAnalyzer, new StatisticalGuard());
        $v1 = $gate->gate($this->makeRequest());  // blip
        $v2 = $gate->gate($this->makeRequest());  // healthy → resets

        self::assertSame(CanaryDecision::Progress, $v2->decision);
    }

    public function test_analyzer_exception_is_inconclusive_not_progress(): void
    {
        $errAnalyzer = new class implements CanaryAnalyzerInterface {
            public function capabilities(): CapabilityDescriptor { return CapabilityDescriptor::create([]); }

            public function analyze(CanaryAnalysisRequest $r): CanaryVerdict
            {
                throw new \RuntimeException('connection refused');
            }
        };

        $gate = new CanaryGate($errAnalyzer, new StatisticalGuard());
        $v = $gate->gate($this->makeRequest());

        self::assertNotSame(CanaryDecision::Progress, $v->decision);
    }

    public function test_reset_clears_breach_state(): void
    {
        $gate = new CanaryGate($this->analyzerReturning(CanaryDecision::Rollback, true), new StatisticalGuard());
        $gate->gate($this->makeRequest());
        $gate->gate($this->makeRequest());
        $gate->reset();

        // After reset, a single breach should not immediately rollback
        $v = $gate->gate($this->makeRequest());
        self::assertNotSame(CanaryDecision::Rollback, $v->decision);
    }
}
