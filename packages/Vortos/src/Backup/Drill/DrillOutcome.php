<?php

declare(strict_types=1);

namespace Vortos\Backup\Drill;

/**
 * What a restore drill proved, judged against the declared recovery objective.
 *
 * Three states, not a boolean, because "the restore worked" and "the restore worked in time" are two
 * different facts and the second is the one a recovery objective promises. Until this existed a
 * point-in-time drill that took an hour against a thirty-minute objective was recorded as `passed`,
 * emitted the same Info event as a two-minute one, and paged nobody — while the replay chain it
 * measured grew by a segment a minute toward a real outage that would miss its objective.
 *
 * The backed values are stored in `backup_drill_report.outcome` (VARCHAR(16)), so they stay short
 * and historical `passed`/`failed` rows keep hydrating.
 */
enum DrillOutcome: string
{
    /** Restored, every invariant held, and it finished inside the recovery time objective. */
    case Passed = 'passed';

    /** Restored and every invariant held, but it took longer than the recovery time objective. */
    case OverObjective = 'over_objective';

    /** The restore or an invariant failed. Nothing about recoverability was proved. */
    case Failed = 'failed';

    /** The data came back intact, whether or not it came back in time. */
    public function restored(): bool
    {
        return $this !== self::Failed;
    }

    public function metObjective(): bool
    {
        return $this === self::Passed;
    }
}
