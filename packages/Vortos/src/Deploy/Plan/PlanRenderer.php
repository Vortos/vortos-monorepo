<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

final class PlanRenderer
{
    public function toText(DeployPlan $plan): string
    {
        $lines = [];
        $lines[] = sprintf('Deploy Plan (%d phases)', $plan->phaseCount());
        $lines[] = sprintf('Hash: %s', $plan->planHash->toString());
        $lines[] = '';

        foreach ($plan->phases as $index => $phase) {
            $lines[] = sprintf('  %d. [%s]', $index + 1, $phase->kind->value);

            foreach ($phase->steps as $step) {
                $parts = sprintf('     → %s: %s', $step->action->value, $step->description);

                if ($step->params !== []) {
                    $paramStr = [];
                    foreach ($step->params as $k => $v) {
                        $paramStr[] = sprintf('%s=%s', $k, (string) $v);
                    }
                    $parts .= ' (' . implode(', ', $paramStr) . ')';
                }

                if ($step->secretReferences !== []) {
                    $parts .= ' [secrets: ' . implode(', ', array_map(
                        static fn ($ref): string => $ref->key->value(),
                        $step->secretReferences,
                    )) . ']';
                }

                $lines[] = $parts;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    public function toJson(DeployPlan $plan): string
    {
        return json_encode($plan->toArray(), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
    }
}
