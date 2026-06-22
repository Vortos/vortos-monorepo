<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

use Vortos\FeatureFlags\Guardrail\MetricSource\GuardrailMetricSourceInterface;

final class GuardrailConditionEvaluator
{
    public function __construct(
        private readonly GuardrailMetricSourceInterface $metricSource,
    ) {}

    /**
     * Returns true = breach, false = clear, null = unknown (metric unavailable).
     */
    public function evaluate(
        GuardrailCondition $condition,
        string $flagName,
        string $environment,
        int $windowSeconds,
    ): ?bool {
        if ($condition->isGroup()) {
            return $this->evaluateGroup($condition, $flagName, $environment, $windowSeconds);
        }

        return $this->evaluateLeaf($condition, $flagName, $environment, $windowSeconds);
    }

    private function evaluateLeaf(
        GuardrailCondition $condition,
        string $flagName,
        string $environment,
        int $windowSeconds,
    ): ?bool {
        if ($condition->metricKind === null || $condition->threshold === null || $condition->comparisonOperator === null) {
            return null;
        }

        $query = new GuardrailMetricQuery(
            metricKind:       $condition->metricKind,
            flagName:         $flagName,
            environment:      $environment,
            windowSeconds:    $windowSeconds,
            customMetricName: $condition->customMetricName,
        );

        $value = $this->metricSource->query($query);

        if ($value === null) {
            return null;
        }

        return match ($condition->comparisonOperator) {
            'gt'  => $value > $condition->threshold,
            'gte' => $value >= $condition->threshold,
            'lt'  => $value < $condition->threshold,
            'lte' => $value <= $condition->threshold,
            default => null,
        };
    }

    private function evaluateGroup(
        GuardrailCondition $condition,
        string $flagName,
        string $environment,
        int $windowSeconds,
    ): ?bool {
        $results = [];
        foreach ($condition->children as $child) {
            $results[] = $this->evaluate($child, $flagName, $environment, $windowSeconds);
        }

        if ($condition->combinator === GuardrailCondition::COMBINATOR_AND) {
            // false if any is false; true if all true; null otherwise
            if (in_array(false, $results, true)) {
                return false;
            }
            if (!in_array(null, $results, true) && !in_array(false, $results, true)) {
                return true;
            }

            return null;
        }

        if ($condition->combinator === GuardrailCondition::COMBINATOR_OR) {
            // true if any is true; false if all false; null otherwise
            if (in_array(true, $results, true)) {
                return true;
            }
            if (!in_array(null, $results, true) && !in_array(true, $results, true)) {
                return false;
            }

            return null;
        }

        return null;
    }
}
