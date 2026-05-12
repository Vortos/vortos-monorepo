<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

enum MetricLabel: string
{
    case Method = 'method';
    case Route = 'route';
    case Status = 'status';
    case Reason = 'reason';
    case Command = 'command';
    case Query = 'query';
    case Event = 'event';
    case Consumer = 'consumer';
    case Result = 'result';
    case Transport = 'transport';
    case Operation = 'operation';
    case Driver = 'driver';
    case Policy = 'policy';
    case Scope = 'scope';
    case Controller = 'controller';
    case Quota = 'quota';
    case Bucket = 'bucket';
    case Period = 'period';
    case Feature = 'feature';
    case Flag = 'flag';
}
