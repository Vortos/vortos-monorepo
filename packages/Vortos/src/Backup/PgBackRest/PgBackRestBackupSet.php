<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use DateTimeImmutable;

/** One backup in the repository, as `pgbackrest info --output=json` describes it. */
final readonly class PgBackRestBackupSet
{
    public function __construct(
        public string $label,
        public PgBackRestBackupType $type,
        public DateTimeImmutable $startedAt,
        public DateTimeImmutable $stoppedAt,
        /** First and last WAL file the backup needs to be consistent. */
        public string $walStart,
        public string $walStop,
        public ?string $lsnStart,
        public ?string $lsnStop,
        public int $databaseBytes,
        /** What this backup added to the repository, after compression and block-level dedup. */
        public int $repositoryDeltaBytes,
        /** pgBackRest found a checksum error in a copied page; the set is kept but must not be trusted. */
        public bool $error,
    ) {}
}
