<?php

declare(strict_types=1);

namespace Vortos\Observability\Telemetry;

final class TelemetryRequestAttributes
{
    public const DROP_TRACE = '_vortos_telemetry_drop_trace';
    public const BLOCKED_REASON = '_vortos_telemetry_blocked_reason';

    private function __construct() {}
}
