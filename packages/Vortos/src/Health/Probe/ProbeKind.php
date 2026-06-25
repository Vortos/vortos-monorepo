<?php

declare(strict_types=1);

namespace Vortos\Health\Probe;

enum ProbeKind: string
{
    case Liveness = 'liveness';
    case Readiness = 'readiness';
    case Startup = 'startup';
}
