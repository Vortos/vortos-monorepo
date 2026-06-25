<?php

declare(strict_types=1);

namespace Vortos\Deploy\Runner;

enum DeployOutcomeStatus: string
{
    /** Doctor was not clear — nothing was mutated. */
    case Refused = 'refused';
    /** Dry-run rehearsal — plan built and previewed, nothing mutated. */
    case DryRun = 'dry-run';
    /** Live deploy completed. */
    case Deployed = 'deployed';
    /** Live deploy failed health and was auto-rolled-back. */
    case RolledBack = 'rolled-back';

    public function isSuccess(): bool
    {
        return $this === self::DryRun || $this === self::Deployed;
    }
}
