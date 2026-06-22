<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Metrics;

use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\FlagRegistryInterface;

/**
 * Transparent metrics decorator over a {@see FlagRegistryInterface} (Block 8).
 *
 * Records evaluation counts, variant assignments, and eval latency without touching the
 * evaluation logic — the inner registry's results are returned unchanged (a contract-parity
 * test asserts this). When metrics are disabled the per-call cost is one null check inside
 * {@see FlagEvaluationMetrics}, so the hot path is effectively untouched.
 */
final class InstrumentedFlagRegistry implements FlagRegistryInterface
{
    public function __construct(
        private readonly FlagRegistryInterface $inner,
        private readonly FlagEvaluationMetrics $metrics,
    ) {}

    public function isEnabled(string $name, FlagContext $context = new FlagContext()): bool
    {
        $start  = hrtime(true);
        $result = $this->inner->isEnabled($name, $context);
        $this->metrics->duration('is_enabled', (hrtime(true) - $start) / 1_000_000);
        $this->metrics->evaluation($name, $result ? FlagEvaluationMetrics::RESULT_ON : FlagEvaluationMetrics::RESULT_OFF);

        return $result;
    }

    public function variant(string $name, FlagContext $context = new FlagContext()): string
    {
        $variant = $this->inner->variant($name, $context);
        if ($variant !== 'control') {
            $this->metrics->variantAssignment($name, $variant);
        }

        return $variant;
    }

    public function allForContext(FlagContext $context = new FlagContext()): array
    {
        $start  = hrtime(true);
        $result = $this->inner->allForContext($context);
        $this->metrics->duration('all_for_context', (hrtime(true) - $start) / 1_000_000);

        return $result;
    }
}
