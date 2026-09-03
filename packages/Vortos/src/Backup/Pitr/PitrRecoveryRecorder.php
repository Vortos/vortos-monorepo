<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

/**
 * Carries a recovery's evidence back from the restore target to whoever asked for the restore.
 *
 * {@see \Vortos\Backup\Restore\RestoreTargetInterface::restore()} returns void, because for every
 * other target there is nothing to report — a logical restore either loaded the dump or threw. A
 * point-in-time restore produces a measurement, and the drill's verdict depends on it.
 *
 * A mutable holder handed IN through {@see \Vortos\Backup\Restore\RestoreRequest::$options} rather
 * than a property on the target, deliberately. Restore targets are container services with a
 * process-long lifetime — under FrankenPHP's worker mode the same instance serves request after
 * request — so a `$lastOutcome` property would be state that outlives the operation that produced
 * it, and the failure mode is a drill reporting the PREVIOUS run's evidence. Ownership stays with
 * the caller, which creates one of these per restore and reads it once.
 */
final class PitrRecoveryRecorder
{
    private ?PitrRecoveryOutcome $outcome = null;

    public function record(PitrRecoveryOutcome $outcome): void
    {
        $this->outcome = $outcome;
    }

    public function outcome(): ?PitrRecoveryOutcome
    {
        return $this->outcome;
    }
}
