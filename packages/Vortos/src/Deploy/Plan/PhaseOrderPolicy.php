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
