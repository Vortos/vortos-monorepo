<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;

final readonly class RecoveryTimeAssessment
{
    public function __construct(
        public ObjectiveStatus $status,
        public ?int $objectiveSeconds,
        public string $reason,
        /** Recovery time if a point-in-time recovery started now. */
        public ?int $projectedSeconds = null,
        /** Recovery time just before the next scheduled base backup resets the replay chain — the cycle's worst. */
        public ?int $projectedAtNextAnchorSeconds = null,
        public ?int $segmentsSinceAnchor = null,
        public ?float $segmentsPerHour = null,
        public ?float $replayMsPerSegment = null,
        public ?int $fixedMs = null,
        public ?DateTimeImmutable $anchorAt = null,
        public ?DateTimeImmutable $nextAnchorAt = null,
        /** When the projection crosses the objective at the measured rates; null if it never does. */
        public ?DateTimeImmutable $breachAt = null,
        public ?string $evidenceDrillId = null,
        public ?DateTimeImmutable $evidenceDrilledAt = null,
    ) {}

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return [
            'status' => $this->status->value,
            'objective_seconds' => $this->objectiveSeconds,
            'projected_seconds' => $this->projectedSeconds,
            'projected_at_next_anchor_seconds' => $this->projectedAtNextAnchorSeconds,
            'segments_since_anchor' => $this->segmentsSinceAnchor,
            'segments_per_hour' => $this->segmentsPerHour !== null ? round($this->segmentsPerHour, 1) : null,
            'replay_ms_per_segment' => $this->replayMsPerSegment !== null ? round($this->replayMsPerSegment, 1) : null,
            'fixed_ms' => $this->fixedMs,
            'anchor_at' => $this->anchorAt?->format(DATE_ATOM),
            'next_anchor_at' => $this->nextAnchorAt?->format(DATE_ATOM),
            'breach_at' => $this->breachAt?->format(DATE_ATOM),
            'evidence_drill_id' => $this->evidenceDrillId,
            'evidence_drilled_at' => $this->evidenceDrilledAt?->format(DATE_ATOM),
            'reason' => $this->reason,
        ];
    }
}
