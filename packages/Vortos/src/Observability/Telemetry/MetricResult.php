<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

enum MetricResult: string
{
    case Ok = 'ok';
    case Hit = 'hit';
    case Miss = 'miss';
    case Allowed = 'allowed';
    case Blocked = 'blocked';
    case Denied = 'denied';
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Acknowledged = 'acknowledged';
    case Rejected = 'rejected';
    case Ignored = 'ignored';
    case DeserializeFailed = 'deserialize_failed';
    case DeadLettered = 'dead_lettered';
}
