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
 *   - vortos_flags_ledger_rows_total           counter  labels: (none)
 *   - vortos_flags_ledger_write_failures_total counter  labels: (none)
 */
final class FlagEvaluationMetrics
{
    public const EVALUATIONS         = 'vortos_flags_evaluations_total';
    public const VARIANT_ASSIGNMENTS = 'vortos_flags_variant_assignments_total';
    public const EVAL_DURATION_MS    = 'vortos_flags_evaluation_duration_ms';
    public const EXPOSURES           = 'vortos_flags_exposures_total';

    /**
     * Rows actually appended to the first-party exposure ledger.
     *
     * Unlabelled on purpose. The obvious label here is `flag`, and it is exactly the wrong
     * one: this counter exists to be compared against {@see self::LEDGER_WRITE_FAILURES} and
     * to show that the ledger is alive at all, neither of which needs a breakdown — and the
     * ledger table itself answers per-flag questions far better than a metric ever could.
     */
    public const LEDGER_ROWS = 'vortos_flags_ledger_rows_total';

    /**
     * Ledger flushes that threw. The single most important number in this subsystem.
     *
     * A failing ledger does not make an experiment readout unavailable, it makes it *wrong*
     * — the missing subjects are the ones whose writes failed, which is not a random subset.
     * Anything above zero here means every readout covering that window is untrustworthy,
     * so this is the counter to alert on, not to chart.
     */
    public const LEDGER_WRITE_FAILURES = 'vortos_flags_ledger_write_failures_total';

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

    /**
     * Record rows appended to the ledger.
     *
     * Guarded against a zero increment: an OTLP counter rejects `increment(0)` with a 400 and
     * the collector drops the *entire* batch it travelled in, taking unrelated metrics with
     * it. A flush that wrote nothing is the common case here — every duplicate exposure that
     * day produces one — so this guard is on the hot path, not an edge case.
     */
    public function ledgerRows(int $rows): void
    {
        if ($rows <= 0) {
            return;
        }

        $this->metrics?->counter(self::LEDGER_ROWS)->increment((float) $rows);
    }

    public function ledgerWriteFailed(): void
    {
        $this->metrics?->counter(self::LEDGER_WRITE_FAILURES)->increment();
    }
}
