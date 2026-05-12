<?php

declare(strict_types=1);

namespace Vortos\Metrics\Config;

use Vortos\Observability\Config\ObservabilityModule;

enum MetricsModule: string
{
    case Http        = 'http';
    case Cqrs        = 'cqrs';
    case Messaging   = 'messaging';
    case Cache       = 'cache';
    case Persistence = 'persistence';

    public function observabilityModule(): ObservabilityModule
    {
        return ObservabilityModule::from($this->value);
    }
}
