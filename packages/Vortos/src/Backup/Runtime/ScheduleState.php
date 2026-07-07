<?php

declare(strict_types=1);

namespace Vortos\Backup\Runtime;

use DateTimeImmutable;

/**
 * The persisted execution state of a single backup schedule, so a restarted worker neither
 * double-fires nor loses its place, and failures back off instead of hot-looping.
 */
final readonly class ScheduleState
{
    public function __construct(
        public ?DateTimeImmutable $lastFiredAt = null,
        public int $consecutiveFailures = 0,
        public ?DateTimeImmutable $retryAfter = null,
    ) {
    }

    public function firedAt(DateTimeImmutable $at): self
    {
        return new self(lastFiredAt: $at, consecutiveFailures: 0, retryAfter: null);
    }

    public function failed(DateTimeImmutable $retryAfter): self
    {
        return new self(
            lastFiredAt: $this->lastFiredAt,
            consecutiveFailures: $this->consecutiveFailures + 1,
            retryAfter: $retryAfter,
        );
    }
}
