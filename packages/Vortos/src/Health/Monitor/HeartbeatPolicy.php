<?php

declare(strict_types=1);

namespace Vortos\Health\Monitor;

use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\Observability\Heartbeat\HeartbeatPing;
use Vortos\Observability\Heartbeat\HeartbeatStatus;

/**
 * Decides the Start/Success/Fail ping from a tick's probe rollup. Reuses Block 15's
 * {@see HeartbeatPing}/{@see HeartbeatStatus} directly — the heartbeat mechanism is
 * never re-implemented, only driven.
 */
final class HeartbeatPolicy
{
    public function start(string $monitorKey): HeartbeatPing
    {
        return HeartbeatPing::create($monitorKey, HeartbeatStatus::Start);
    }

    /** @param list<ProbeResult> $results */
    public function finish(string $monitorKey, array $results): HeartbeatPing
    {
        foreach ($results as $result) {
            if ($result->status === ProbeStatus::Fail) {
                return HeartbeatPing::create(
                    $monitorKey,
                    HeartbeatStatus::Fail,
                    sprintf('%s:%s', $result->name, $result->errorCode ?? 'failed'),
                );
            }
        }

        return HeartbeatPing::create($monitorKey, HeartbeatStatus::Success);
    }
}
