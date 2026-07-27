<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Doctor;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Doctor\ReplicationAccessInspector;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;

/**
 * pg_basebackup needs a REPLICATION connection, which PostgreSQL authorises through a separate
 * pg_hba.conf path that `all` does not cover. Nothing else in the backup preflight looks at it, so
 * physical base backups can fail forever while every other signal reports healthy — and archived
 * WAL with no base backup restores to nothing.
 */
final class ReplicationAccessInspectorTest extends TestCase
{
    private const DSN = 'postgresql://app:secret@db:5432/app';

    public function test_it_fails_when_the_server_refuses_a_replication_connection(): void
    {
        // The exact production failure: ordinary queries work, replication is refused.
        $inspector = new ReplicationAccessInspector(
            static fn (string $dsn): array => [
                'ok' => false,
                'error' => 'FATAL: no pg_hba entry for replication connection from host "172.18.0.3"',
            ],
        );

        $finding = $inspector->inspect(DatabaseEngine::Postgres, self::DSN, [BackupKind::PhysicalBase]);

        self::assertTrue($finding->isFailure());
        self::assertStringContainsString('unrestorable', $finding->message);
        self::assertStringContainsString('ALTER ROLE', $finding->remediation);
        self::assertStringContainsString('host replication', $finding->remediation);
    }

    public function test_it_passes_when_replication_is_accepted(): void
    {
        $inspector = new ReplicationAccessInspector(
            static fn (string $dsn): array => ['ok' => true, 'error' => null],
        );

        $finding = $inspector->inspect(DatabaseEngine::Postgres, self::DSN, [BackupKind::PhysicalBase]);

        self::assertFalse($finding->isFailure());
        self::assertTrue($finding->satisfied);
    }

    public function test_it_probes_on_a_replication_connection_specifically(): void
    {
        // A plain connection proves nothing: it is authorised by a different pg_hba rule. The probe
        // must request replication=database or it would pass while pg_basebackup still fails.
        $seen = null;
        $inspector = new ReplicationAccessInspector(
            static function (string $dsn) use (&$seen): array {
                $seen = $dsn;

                return ['ok' => true, 'error' => null];
            },
        );

        $inspector->inspect(DatabaseEngine::Postgres, self::DSN, [BackupKind::PhysicalBase]);

        self::assertIsString($seen);
        self::assertStringContainsString('replication=database', $seen);
    }

    public function test_it_does_not_gate_a_setup_that_takes_no_physical_base_backups(): void
    {
        // Logical dumps need no replication access. Failing them on it would be a false alarm that
        // blocks deploys for a capability the environment never asked for.
        $inspector = new ReplicationAccessInspector(
            static fn (string $dsn): array => ['ok' => false, 'error' => 'refused'],
        );

        $finding = $inspector->inspect(DatabaseEngine::Postgres, self::DSN, [BackupKind::LogicalFull]);

        self::assertFalse($finding->isFailure());
        self::assertFalse($finding->applicable);
    }

    public function test_it_is_not_applicable_to_non_postgres_engines(): void
    {
        $inspector = new ReplicationAccessInspector(
            static fn (string $dsn): array => ['ok' => false, 'error' => 'refused'],
        );

        $finding = $inspector->inspect(DatabaseEngine::Mongo, self::DSN, [BackupKind::PhysicalBase]);

        self::assertFalse($finding->isFailure());
        self::assertFalse($finding->applicable);
    }
}
