<?php

declare(strict_types=1);

namespace Vortos\Observability\Heartbeat;

use InvalidArgumentException;

/**
 * One check-in to an external dead-man monitor.
 *
 * The dead-man's switch is the only detector that catches "host dead AND its
 * monitoring dead" (§12.4): the app pushes these pings; *absence* of a ping is what
 * pages, and that absence is detected off-host. This VO is the emit-side payload.
 */
final readonly class HeartbeatPing
{
    private function __construct(
        public string $monitorKey,
        public HeartbeatStatus $status,
        public ?string $note,
    ) {}

    public static function create(string $monitorKey, HeartbeatStatus $status, ?string $note = null): self
    {
        $monitorKey = trim($monitorKey);
        if ($monitorKey === '') {
            throw new InvalidArgumentException('Heartbeat monitorKey must be a non-empty string.');
        }

        if ($note !== null && strlen($note) > 500) {
            $note = substr($note, 0, 500);
        }

        return new self($monitorKey, $status, $note);
    }
}
