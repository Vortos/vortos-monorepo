<?php

declare(strict_types=1);

namespace Vortos\Observability\Slo;

/**
 * Pure render of an {@see Slo} + {@see BurnRatePolicy} into a backend-agnostic
 * recording/alerting-rule artifact (Block 16, §3.4). Consumed by Block 17 (alerts)
 * and readable by Block 22 (canary error-budget burn) — the only backend-named
 * output sits behind a future alerting driver, never here.
 */
final class SloArtifactRenderer
{
    /** @return array<string, mixed> */
    public function render(Slo $slo, BurnRatePolicy $policy): array
    {
        $budget = $slo->errorBudget();

        return [
            'name' => $slo->name,
            'objective' => $slo->objective,
            'error_budget_fraction' => $budget->fraction,
            'window_seconds' => $slo->window->seconds,
            'indicator_ref' => $slo->indicatorRef,
            'burn_rate' => [
                'fast' => [
                    'window_seconds' => $policy->fastWindow->seconds,
                    'threshold' => $policy->fastThreshold,
                ],
                'slow' => [
                    'window_seconds' => $policy->slowWindow->seconds,
                    'threshold' => $policy->slowThreshold,
                ],
            ],
        ];
    }

    /**
     * @param list<array{0: Slo, 1: BurnRatePolicy}> $definitions
     * @return list<array<string, mixed>>
     */
    public function renderAll(array $definitions): array
    {
        return array_map(
            fn (array $pair) => $this->render($pair[0], $pair[1]),
            $definitions,
        );
    }
}
