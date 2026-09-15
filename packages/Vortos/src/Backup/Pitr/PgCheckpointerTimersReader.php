<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Reads the checkpointer's timers in one statement, so every value describes the same instant.
 *
 * Seconds come from pg_settings.setting (always the base unit for archive_timeout), never from SHOW, whose
 * "1min" form is for people. Timestamps come back as epoch seconds for the same reason
 * {@see \Vortos\Backup\DR\PgStatArchiverStatusReader} does: text timestamps depend on the session's
 * DateStyle and TimeZone. pg_control_checkpoint() needs superuser or an explicit EXECUTE grant.
 */
final class PgCheckpointerTimersReader implements CheckpointerTimersReaderInterface
{
    public function __construct(private readonly Connection $connection) {}

    public function read(): CheckpointerTimers
    {
        $row = $this->connection->fetchAssociative(
            "SELECT (SELECT setting FROM pg_settings WHERE name = 'archive_mode') AS archive_mode,
                    (SELECT setting::int FROM pg_settings WHERE name = 'archive_timeout') AS archive_timeout,
                    pg_is_in_recovery() AS in_recovery,
                    floor(extract(epoch FROM pg_postmaster_start_time()))::bigint AS started_epoch,
                    floor(extract(epoch FROM (pg_control_checkpoint()).checkpoint_time))::bigint AS checkpoint_epoch",
        );

        if ($row === false || !is_numeric($row['started_epoch'] ?? null) || !is_numeric($row['checkpoint_epoch'] ?? null)) {
            throw new RuntimeException('the checkpointer timers could not be read');
        }

        return new CheckpointerTimers(
            archivingEnabled: \in_array((string) $row['archive_mode'], ['on', 'always'], true),
            archiveTimeoutSeconds: (int) $row['archive_timeout'],
            inRecovery: filter_var($row['in_recovery'], FILTER_VALIDATE_BOOLEAN),
            postmasterStartedAt: new DateTimeImmutable('@' . (int) $row['started_epoch']),
            lastCheckpointAt: new DateTimeImmutable('@' . (int) $row['checkpoint_epoch']),
        );
    }
}
