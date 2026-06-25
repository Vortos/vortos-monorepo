<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary\Driver;

use Vortos\Deploy\Canary\CanaryAnalysisRequest;
use Vortos\Deploy\Canary\CanaryAnalyzerInterface;
use Vortos\Deploy\Canary\CanaryComparator;
use Vortos\Deploy\Canary\CanaryDecision;
use Vortos\Deploy\Canary\CanaryInstantResult;
use Vortos\Deploy\Canary\CanaryMetricSpec;
use Vortos\Deploy\Canary\CanaryMetricsPort;
use Vortos\Deploy\Canary\CanaryVerdict;
use Vortos\Deploy\Canary\MetricEvaluation;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Canary analyzer: queries a CanaryMetricsPort for staged and stable colors,
 * evaluates each CanaryMetricSpec, and returns a per-interval verdict.
 *
 * Sustained-breach accumulation is handled by CanaryGate (stateful wrapper),
 * not here. This analyzer returns:
 *   - Rollback: any metric is breached this interval
 *   - Progress: all metrics within tolerance
 *   - Inconclusive: no samples or metrics port error
 *
 * Fail-closed: any exception from the port → Inconclusive (never Progress).
 */
#[AsDriver('slo-prometheus')]
final class SloPrometheusCanaryAnalyzer implements CanaryAnalyzerInterface
{
    public function __construct(
        private readonly CanaryMetricsPort $metricsPort,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([]);
    }

    public function analyze(CanaryAnalysisRequest $request): CanaryVerdict
    {
        $now = $request->at;
        $evaluations = [];
        $totalSamples = 0;

        foreach ($request->specs as $spec) {
            try {
                [$eval, $samples] = $this->evaluateSpec($spec, $request);
                $evaluations[] = $eval;
                $totalSamples = max($totalSamples, $samples);
            } catch (\Throwable $e) {
                $evaluations[] = new MetricEvaluation(
                    sloName: $spec->slo->name,
                    comparator: $spec->comparator,
                    stagedValue: \NAN,
                    stableValue: null,
                    breached: false,
                    reason: sprintf('query error: %s', $e->getMessage()),
                );

                return $this->inconclusiveVerdict($evaluations, $now, 'metrics query error — fail-closed');
            }
        }

        if ($totalSamples === 0) {
            return $this->inconclusiveVerdict($evaluations, $now, 'no samples available');
        }

        $breachedNames = array_map(
            static fn (MetricEvaluation $e): string => $e->sloName,
            array_filter($evaluations, static fn (MetricEvaluation $e): bool => $e->breached),
        );

        if ($breachedNames !== []) {
            $reason = sprintf('SLO breach: %s', implode(', ', $breachedNames));

            return new CanaryVerdict(CanaryDecision::Rollback, $evaluations, $reason, $totalSamples, $now);
        }

        return new CanaryVerdict(CanaryDecision::Progress, $evaluations, 'all SLOs within tolerance', $totalSamples, $now);
    }

    /** @return array{MetricEvaluation, int} */
    private function evaluateSpec(CanaryMetricSpec $spec, CanaryAnalysisRequest $request): array
    {
        $staged = $this->metricsPort->instant($spec->slo->indicatorRef, $request->staged->value);

        if ($staged->isEmpty()) {
            return [new MetricEvaluation(
                sloName: $spec->slo->name,
                comparator: $spec->comparator,
                stagedValue: \NAN,
                stableValue: null,
                breached: false,
                reason: 'no staged samples',
            ), 0];
        }

        $stagedValue = $staged->value;
        $stableValue = null;
        $breached = false;
        $reason = '';

        if ($spec->comparator === CanaryComparator::RelativeToBaseline) {
            $stable = $this->metricsPort->instant($spec->slo->indicatorRef, $request->stable->value);

            if ($stable->isEmpty()) {
                return [new MetricEvaluation(
                    sloName: $spec->slo->name,
                    comparator: $spec->comparator,
                    stagedValue: $stagedValue,
                    stableValue: null,
                    breached: false,
                    reason: 'no stable baseline available',
                ), $staged->sampleCount];
            }

            $stableValue = $stable->value;
            $threshold = $stableValue * (1 + $spec->tolerance);

            if ($spec->lowerIsBetter) {
                $breached = $stagedValue > $threshold;
                $reason = $breached
                    ? sprintf('staged %.4f > baseline×(1+%.2f)=%.4f', $stagedValue, $spec->tolerance, $threshold)
                    : sprintf('staged %.4f ≤ baseline×(1+%.2f)=%.4f', $stagedValue, $spec->tolerance, $threshold);
            } else {
                $breached = $stagedValue < ($stableValue * (1 - $spec->tolerance));
                $reason = $breached
                    ? sprintf('staged %.4f < baseline×(1-%.2f)', $stagedValue, $spec->tolerance)
                    : 'staged within tolerance of baseline';
            }
        } else {
            $threshold = $spec->slo->objective;
            if ($spec->lowerIsBetter) {
                $breached = $stagedValue > $threshold;
                $reason = $breached
                    ? sprintf('staged %.4f > threshold %.4f', $stagedValue, $threshold)
                    : sprintf('staged %.4f ≤ threshold %.4f', $stagedValue, $threshold);
            } else {
                $breached = $stagedValue < $threshold;
                $reason = $breached
                    ? sprintf('staged %.4f < threshold %.4f', $stagedValue, $threshold)
                    : sprintf('staged %.4f ≥ threshold %.4f', $stagedValue, $threshold);
            }
        }

        return [new MetricEvaluation(
            sloName: $spec->slo->name,
            comparator: $spec->comparator,
            stagedValue: $stagedValue,
            stableValue: $stableValue,
            breached: $breached,
            reason: $reason,
        ), $staged->sampleCount];
    }

    /** @param list<MetricEvaluation> $evaluations */
    private function inconclusiveVerdict(
        array $evaluations,
        \DateTimeImmutable $now,
        string $reason,
    ): CanaryVerdict {
        return new CanaryVerdict(CanaryDecision::Inconclusive, $evaluations, $reason, 0, $now);
    }
}
