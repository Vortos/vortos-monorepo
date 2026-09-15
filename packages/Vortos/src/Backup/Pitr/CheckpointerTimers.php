<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use DateTimeImmutable;

/**
 * The server facts that decide whether PostgreSQL's archive_timeout is being honoured: whether it is on at
 * all, when this postmaster started, and when the last checkpoint completed. Read from the server itself, so
 * the configuration has exactly one source — the file the database was started with.
 */
final readonly class CheckpointerTimers
{
    public function __construct(
        /** archive_mode is on (or always). */
        public bool $archivingEnabled,
        /** archive_timeout in seconds; 0 means the server forces no switches at all. */
        public int $archiveTimeoutSeconds,
        public bool $inRecovery,
        public DateTimeImmutable $postmasterStartedAt,
        /** Time of the latest checkpoint in pg_control; before postmasterStartedAt means none since this start. */
        public DateTimeImmutable $lastCheckpointAt,
    ) {}

    public function checkpointedSinceStart(): bool
    {
        return $this->lastCheckpointAt >= $this->postmasterStartedAt;
    }
}
