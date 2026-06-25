<?php

declare(strict_types=1);

namespace Vortos\Observability\Heartbeat;

/**
 * Emits a dead-man's-switch check-in to an external monitor.
 *
 * The contract that matters is on the *absence* side, detected off-host: this is only
 * the push. Implementations must be bounded (hard timeout) and must never block or
 * throw into a scheduler loop.
 */
interface HeartbeatEmitterInterface
{
    /** Send one ping. Returns true if the external monitor acknowledged it. */
    public function emit(HeartbeatPing $ping): bool;
}
