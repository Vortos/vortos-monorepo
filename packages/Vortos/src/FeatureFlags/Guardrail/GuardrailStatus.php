<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Guardrail;

enum GuardrailStatus: string
{
    case Watching  = 'watching';
    case Triggered = 'triggered';
    case Resolved  = 'resolved';
}
