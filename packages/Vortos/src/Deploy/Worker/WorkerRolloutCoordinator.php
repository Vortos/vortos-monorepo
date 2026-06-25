<?php

declare(strict_types=1);

namespace Vortos\Deploy\Worker;

use Vortos\Deploy\Registry\ImageReference;

final class WorkerRolloutCoordinator
{
    public function __construct(
        private readonly WorkerControllerInterface $controller,
    ) {}

    /** @return list<DrainOutcome> */
    public function rollout(WorkerRolloutPlan $plan, ImageReference $image, DrainBudget $budget): array
    {
        if ($plan->isEmpty()) {
            return [];
        }

        $outcomes = [];

        foreach ($plan->handles as $handle) {
            $outcome = $this->controller->drain($handle, $budget);
            $outcomes[] = $outcome;

            $this->controller->launch($handle, $image);

            $this->awaitRunning($handle);
        }

        return $outcomes;
    }

    private function awaitRunning(WorkerHandle $handle): void
    {
        $maxAttempts = 30;
        $sleepMs = 500;

        for ($i = 0; $i < $maxAttempts; $i++) {
            $status = $this->controller->status($handle);
            if ($status->isRunning()) {
                return;
            }
            if ($status === WorkerRuntimeStatus::Fatal) {
                throw new \RuntimeException(sprintf(
                    'Worker "%s" entered FATAL state after launch.',
                    $handle->programName,
                ));
            }
            usleep($sleepMs * 1000);
        }

        throw new \RuntimeException(sprintf(
            'Worker "%s" did not reach RUNNING state within %dms.',
            $handle->programName,
            $maxAttempts * $sleepMs,
        ));
    }

    /** @param list<DrainOutcome> $outcomes */
    public static function summarize(array $outcomes): string
    {
        $total = \count($outcomes);
        $graceful = 0;
        $forced = 0;
        $totalMs = 0;

        foreach ($outcomes as $outcome) {
            if ($outcome->inFlightCompleted) {
                $graceful++;
            }
            if ($outcome->forced) {
                $forced++;
            }
            $totalMs += $outcome->durationMs;
        }

        return sprintf(
            'rolled %d workers (graceful=%d, forced=%d, %dms)',
            $total,
            $graceful,
            $forced,
            $totalMs,
        );
    }
}
