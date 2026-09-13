<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;

/**
 * The cluster's own account of continuous archiving — the only place that knows whether WAL exists
 * that no archive holds yet.
 */
final readonly class ArchiverStatus
{
    public function __construct(
        public ?string $lastArchivedWal,
        public ?DateTimeImmutable $lastArchivedAt,
        public ?string $lastFailedWal,
        public ?DateTimeImmutable $lastFailedAt,
        /** The segment currently being written; null when the server is in recovery (a standby). */
        public ?string $currentWal,
    ) {}

    /**
     * Is there written WAL that has not been archived?
     *
     * WAL file names sort in log order within a timeline, and a later timeline sorts after an earlier
     * one, so a plain string comparison is exact. `pg_walfile_name()` of an LSN sitting exactly on a
     * segment boundary names the PREVIOUS segment, which is what makes "current equals last archived"
     * mean "nothing written since the last switch" rather than an off-by-one.
     */
    public function hasUnarchivedWal(): bool
    {
        if ($this->currentWal === null) {
            return false;
        }

        return $this->lastArchivedWal === null || strcmp($this->currentWal, $this->lastArchivedWal) > 0;
    }

    public function isFailing(): bool
    {
        return $this->lastFailedAt !== null
            && ($this->lastArchivedAt === null || $this->lastFailedAt > $this->lastArchivedAt);
    }
}
