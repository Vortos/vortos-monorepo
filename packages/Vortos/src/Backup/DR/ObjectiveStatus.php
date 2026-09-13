<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

enum ObjectiveStatus: string
{
    /** Inside the objective now, and (for RTO) still inside it when the next base backup is due. */
    case Met = 'met';

    /** Inside the objective now, but the measured trend crosses it before the next base backup can reset it. */
    case AtRisk = 'at_risk';

    /** A recovery started right now would miss the objective. */
    case Breached = 'breached';

    /** The measurement could not be made. Never reported as met: an unmeasured objective is not a kept one. */
    case Indeterminate = 'indeterminate';

    /** No objective is declared for this installation (no backup configuration at all). */
    case Undeclared = 'undeclared';

    public function pages(): bool
    {
        return $this === self::AtRisk || $this === self::Breached;
    }
}
