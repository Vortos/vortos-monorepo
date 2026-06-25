<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

enum CanaryDecision
{
    case Progress;
    case Hold;
    case Rollback;
    /** Insufficient samples — gate cannot decide yet. Treated as Hold; fail-closed past deadline. */
    case Inconclusive;
}
