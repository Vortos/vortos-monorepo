<?php

declare(strict_types=1);

namespace Vortos\Metrics\Config;

enum MetricsModule: string
{
    case Http        = 'http';
    case Cqrs        = 'cqrs';
    case Messaging   = 'messaging';
    case Cache       = 'cache';
    case Persistence = 'persistence';
}
