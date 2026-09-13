<?php

declare(strict_types=1);

namespace Vortos\Backup\DR;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Reads `pg_stat_archiver` and the segment being written, in one statement so the two describe the
 * same instant.
 *
 * `pg_stat_archiver` is readable by any role and `pg_current_wal_insert_lsn()` needs no privilege, so
 * this works on a least-privilege connection. Timestamps come back as epoch seconds rather than text:
 * the text form's offset shape depends on the session's DateStyle and TimeZone, and parsing it is
 * exactly the kind of quiet timezone shift the catalog code has already been burned by.
 */
final class PgStatArchiverStatusReader implements ArchiverStatusReaderInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function read(): ArchiverStatus
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.last_archived_wal,
                    floor(extract(epoch FROM a.last_archived_time))::bigint AS last_archived_epoch,
                    a.last_failed_wal,
                    floor(extract(epoch FROM a.last_failed_time))::bigint AS last_failed_epoch,
                    CASE WHEN pg_is_in_recovery() THEN NULL
                         ELSE pg_walfile_name(pg_current_wal_insert_lsn()) END AS current_wal
               FROM pg_stat_archiver a',
        );

        if ($row === false) {
            throw new RuntimeException('pg_stat_archiver returned no row');
        }

        return new ArchiverStatus(
            self::text($row['last_archived_wal'] ?? null),
            self::instant($row['last_archived_epoch'] ?? null),
            self::text($row['last_failed_wal'] ?? null),
            self::instant($row['last_failed_epoch'] ?? null),
            self::text($row['current_wal'] ?? null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $epoch): ?DateTimeImmutable
    {
        return is_numeric($epoch) ? new DateTimeImmutable('@' . (int) $epoch) : null;
    }
}
