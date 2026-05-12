<?php
declare(strict_types=1);

namespace Vortos\Tracing\Config;

use Vortos\Observability\Config\ObservabilityModule;

enum TracingModule: string
{
    case Http        = 'http';
    case Auth        = 'auth';
    case Authorization = 'authorization';
    case Cache       = 'cache';
    case Persistence = 'persistence';
    case Messaging   = 'messaging';
    case Cqrs        = 'cqrs';
    case RateLimit   = 'rate_limit';
    case Quota       = 'quota';
    case Audit       = 'audit';

    public function observabilityModule(): ObservabilityModule
    {
        return ObservabilityModule::fromLegacy($this->value);
    }
}
