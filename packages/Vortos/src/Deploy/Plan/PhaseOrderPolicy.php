<?php

declare(strict_types=1);

namespace Vortos\Deploy\Plan;

final readonly class PhaseOrderPolicy
{
    /** @param list<DeployPhase> $phases */
    public function assertValid(array $phases): void
    {
        $this->assertWorkersBeforeCutover($phases);
        $this->assertWorkersBeforeStageColor($phases);
        $this->assertDecommissionIsLast($phases);
    }

    /**
     * Decommission tears down the previous color and must be the terminal phase — it may run only after
     * StageColor/HealthGate/Smoke/Cutover/Promote have all succeeded. Any phase after it would act on a
     * topology from which the old color has already been removed.
     *
     * @param list<DeployPhase> $phases
     */
    private function assertDecommissionIsLast(array $phases): void
    {
        $decommissionIndex = null;

        foreach ($phases as $i => $phase) {
            if ($phase->kind === PhaseKind::Decommission) {
                $decommissionIndex = $i;
            }
        }

        if ($decommissionIndex !== null && $decommissionIndex !== count($phases) - 1) {
            throw new \LogicException(
                'Phase ordering violation: Decommission must be the final phase. '
                . 'Tearing down the previous color before every gate has passed is unsafe.',
            );
        }
    }

    /** @param list<DeployPhase> $phases */
    private function assertWorkersBeforeCutover(array $phases): void
    {
        $cutoverIndex = null;
        $lastWorkerIndex = null;

        foreach ($phases as $i => $phase) {
            if ($phase->kind === PhaseKind::Cutover) {
                $cutoverIndex ??= $i;
            }
            if ($phase->kind === PhaseKind::RollWorkers) {
                $lastWorkerIndex = $i;
            }
        }

        if ($cutoverIndex !== null && $lastWorkerIndex !== null && $lastWorkerIndex > $cutoverIndex) {
            throw new \LogicException(
                'Phase ordering violation: RollWorkers must precede Cutover. '
                . 'Workers rolling after cutover risks double-consumption.',
            );
        }
    }

    /** @param list<DeployPhase> $phases */
    private function assertWorkersBeforeStageColor(array $phases): void
    {
        $stageIndex = null;
        $lastWorkerIndex = null;

        foreach ($phases as $i => $phase) {
            if ($phase->kind === PhaseKind::StageColor) {
                $stageIndex ??= $i;
            }
            if ($phase->kind === PhaseKind::RollWorkers) {
                $lastWorkerIndex = $i;
            }
        }

        if ($stageIndex !== null && $lastWorkerIndex !== null && $lastWorkerIndex > $stageIndex) {
            throw new \LogicException(
                'Phase ordering violation: RollWorkers must precede StageColor. '
                . 'Both old and new workers must tolerate the expanded schema.',
            );
        }
    }

    /** @param list<DeployPhase> $phases */
    public static function hasPhase(array $phases, PhaseKind $kind): bool
    {
        foreach ($phases as $phase) {
            if ($phase->kind === $kind) {
                return true;
            }
        }

        return false;
    }
}
