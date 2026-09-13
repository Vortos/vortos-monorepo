<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Gate;

/**
 * Whether a failing check may stop a release.
 *
 * Every check that can gate a deployment declares one of these, and there is deliberately no
 * default. A default is what let this go wrong twice: a check reading LIVE runtime state inherited
 * "blocks the deploy" simply because its author did not think to say otherwise, and the gate then
 * refused the very release that cured the state it was reporting. C14 (overdue schedules) vetoed the
 * fix for the bug that had stalled them; C15 (stranded fires) vetoed the consumer that reclaims them.
 * Each was patched by opting that one check out, which left the next runtime-state check to repeat
 * it. Making the question unanswerable-by-omission is the fix for the class.
 *
 * The rule for choosing:
 *
 *   Blocking — "would shipping THIS release, as configured, be broken or unsafe?" Configuration,
 *              capability, schema safety, supply chain, the truthfulness of the release record.
 *              The release is what is wrong, so refusing it is the remedy.
 *
 *   Advisory — "is the RUNNING system unhealthy, in a way this release neither causes nor
 *              worsens?" Backlogs, overdue work, dead letters, drift in state the release does not
 *              own. A deployment is frequently the cure, so a veto deadlocks the fix behind the
 *              symptom. Advisory failures are still reported on every run and must be covered by
 *              continuous alerting — a deploy gate is not a monitoring system.
 *
 * When a check cannot tell which of the two it is looking at (a dependency that is unreachable
 * because the release's own configuration is wrong, or because the provider is down), it is
 * Blocking: fail closed, and say so in the check.
 */
enum GateDisposition: string
{
    case Blocking = 'blocking';
    case Advisory = 'advisory';

    /**
     * @param bool $strict the operator asked for advisory failures to be treated as blocking
     *                     (e.g. `deploy:doctor --strict` before a risky change)
     */
    public function blocksRelease(bool $strict = false): bool
    {
        return match ($this) {
            self::Blocking => true,
            self::Advisory => $strict,
        };
    }
}
