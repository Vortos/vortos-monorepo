<?php

declare(strict_types=1);

namespace Vortos\Health\Uptime;

/**
 * `Unknown` is distinct from `Down` by design: a provider outage or a bounded-timeout
 * read failure is not evidence the app is down (§5 — "unknown must not page as down").
 * Persistent `Unknown` is its own alert (the detector itself is blind), never a
 * silent reclassification to `Down`.
 */
enum MonitorState: string
{
    case Up = 'up';
    case Down = 'down';
    case Degraded = 'degraded';
    case Unknown = 'unknown';
}
