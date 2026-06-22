<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

enum GuardrailAction: string
{
    case Disable   = 'disable';
    case PauseRamp = 'pause_ramp';
}
