<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Metrics;

use Vortos\Metrics\Contract\MetricsInterface;

/**
 * Emits feature-flag evaluation/exposure metrics through the framework {@see MetricsInterface}
 * (Block 8). Default backing is NoOp — when no metrics impl is wired this is genuinely
 * zero cost (the `?MetricsInterface` is null and every method early-returns).
 *
 * ## Cardinality safety (load-bearing)
 *
 * Labels are restricted to **bounded** dimensions only: `flag` (name), `variant`, `result`.
 * Identifiers (userId, tenantId, requestId) are NEVER labels — they would explode
 * Prometheus cardinality. The exposure ingest path additionally rejects unknown flag names
 * so an attacker cannot mint arbitrary `flag` label values. A test pins the label key set.
 *
 * Metric names (declare these in your metrics config for Prometheus HELP/bucket output):
 *   - vortos_flags_evaluations_total           counter  labels: flag, result
 *   - vortos_flags_variant_assignments_total   counter  labels: flag, variant
 *   - vortos_flags_evaluation_duration_ms      histogram labels: operation
 *   - vortos_flags_exposures_total             counter  labels: flag, variant
 */
final class FlagEvaluationMetrics
{
    public const EVALUATIONS         = 'vortos_flags_evaluations_total';
    public const VARIANT_ASSIGNMENTS = 'vortos_flags_variant_assignments_total';
    public const EVAL_DURATION_MS    = 'vortos_flags_evaluation_duration_ms';
    public const EXPOSURES           = 'vortos_flags_exposures_total';

    public const RESULT_ON      = 'on';
    public const RESULT_OFF     = 'off';

    public function __construct(private readonly ?MetricsInterface $metrics = null) {}

    public function evaluation(string $flag, string $result): void
    {
        $this->metrics?->counter(self::EVALUATIONS, ['flag' => $flag, 'result' => $result])->increment();
    }

    public function variantAssignment(string $flag, string $variant): void
    {
        $this->metrics?->counter(self::VARIANT_ASSIGNMENTS, ['flag' => $flag, 'variant' => $variant])->increment();
    }

    public function duration(string $operation, float $milliseconds): void
    {
        $this->metrics?->histogram(self::EVAL_DURATION_MS, ['operation' => $operation])->observe($milliseconds);
    }

    public function exposure(string $flag, ?string $variant): void
    {
        $this->metrics?->counter(self::EXPOSURES, ['flag' => $flag, 'variant' => $variant ?? 'none'])->increment();
    }
}
