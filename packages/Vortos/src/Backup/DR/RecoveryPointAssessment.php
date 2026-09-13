<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;

final readonly class RecoveryPointAssessment
{
    public function __construct(
        public ObjectiveStatus $status,
        public ?int $objectiveSeconds,
        /** Seconds of written WAL no archive holds — what a total loss of the cluster would lose right now. */
        public ?int $exposureSeconds,
        public ?ArchiverStatus $archiver,
        public string $reason,
    ) {}

    /** @return array<string, mixed> */
    public function toDetail(): array
    {
        return [
            'status' => $this->status->value,
            'objective_seconds' => $this->objectiveSeconds,
            'exposure_seconds' => $this->exposureSeconds,
            'last_archived_wal' => $this->archiver?->lastArchivedWal,
            'last_archived_at' => self::format($this->archiver?->lastArchivedAt),
            'last_failed_wal' => $this->archiver?->lastFailedWal,
            'last_failed_at' => self::format($this->archiver?->lastFailedAt),
            'current_wal' => $this->archiver?->currentWal,
            'reason' => $this->reason,
        ];
    }

    private static function format(?DateTimeImmutable $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }
}
