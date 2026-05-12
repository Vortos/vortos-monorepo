<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

enum MetricOperation: string
{
    case Get = 'get';
    case Set = 'set';
    case Delete = 'delete';
    case Clear = 'clear';
    case Has = 'has';
    case Query = 'query';
    case Execute = 'execute';
}
