<?php

declare(strict_types=1);

namespace Vortos\Observability\Buffer;

/**
 * A point-in-time snapshot of a {@see BoundedSpool}: how full it is, how many records
 * it holds, the age of its oldest record, and how many records have ever been
 * dropped because the byte cap was reached.
 *
 * `droppedTotal` is the signal that "the buffer is silently discarding telemetry" —
 * it is emitted as a counter so a persistent outage is visible, not invisible.
 */
final readonly class SpoolStats
{
    public function __construct(
        public int $sizeBytes,
        public int $maxBytes,
        public int $recordCount,
        public int $oldestAgeMs,
        public int $droppedTotal,
    ) {}

    public function fillRatio(): float
    {
        return $this->maxBytes > 0 ? $this->sizeBytes / $this->maxBytes : 0.0;
    }

    /**
     * @return array{sizeBytes:int, maxBytes:int, recordCount:int, oldestAgeMs:int, droppedTotal:int}
     */
    public function toArray(): array
    {
        return [
            'sizeBytes' => $this->sizeBytes,
            'maxBytes' => $this->maxBytes,
            'recordCount' => $this->recordCount,
            'oldestAgeMs' => $this->oldestAgeMs,
            'droppedTotal' => $this->droppedTotal,
        ];
    }
}
