<?php

declare(strict_types=1);

namespace Vortos\Observability\Heartbeat;

/**
 * The lifecycle phase a heartbeat ping reports to the external monitor.
 *
 * Many dead-man services accept a `/start` then `/success` (or `/fail`) to also
 * measure run duration; absence of `success` within the grace window pages.
 */
enum HeartbeatStatus: string
{
    case Start = 'start';
    case Success = 'success';
    case Fail = 'fail';

    /** Path suffix appended to the monitor base URL (empty = success). */
    public function urlSuffix(): string
    {
        return match ($this) {
            self::Start => '/start',
            self::Success => '',
            self::Fail => '/fail',
        };
    }
}
