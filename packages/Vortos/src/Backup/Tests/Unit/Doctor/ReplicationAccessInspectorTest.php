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

    /**
     * FB-63. The application DSN is Doctrine's `pgsql://`, which libpq rejects as an invalid
     * connection option — the check failed on a healthy production cluster and pointed the operator
     * at pg_hba.conf in the middle of a rebuild.
     */
    public function test_it_hands_libpq_a_scheme_it_accepts_when_given_a_doctrine_dsn(): void
    {
        $seen = null;
        $inspector = new ReplicationAccessInspector(
            static function (string $dsn) use (&$seen): array {
                $seen = $dsn;

                return ['ok' => true, 'error' => null];
            },
        );

        $inspector->inspect(
            DatabaseEngine::Postgres,
            'pgsql://app:secret@db:5432/app?serverVersion=18&charset=utf8',
            [BackupKind::PhysicalBase],
        );

        self::assertSame(
            'postgresql://app:secret@db:5432/app?serverVersion=18&charset=utf8&replication=database',
            $seen,
        );
    }

    public function test_a_libpq_scheme_passes_through_unchanged(): void
    {
        $seen = null;
        $inspector = new ReplicationAccessInspector(
            static function (string $dsn) use (&$seen): array {
                $seen = $dsn;

                return ['ok' => true, 'error' => null];
            },
        );

        $inspector->inspect(DatabaseEngine::Postgres, 'postgres://app@db/app', [BackupKind::PhysicalBase]);

        self::assertSame('postgres://app@db/app?replication=database', $seen);
    }

    public function test_the_password_is_split_out_so_it_never_reaches_argv(): void
    {
        self::assertSame(
            ['dsn' => 'postgresql://app@db:5432/app?replication=database', 'password' => 'p@ss:w/rd'],
            ReplicationAccessInspector::withoutPassword('postgresql://app:p%40ss%3Aw%2Frd@db:5432/app?replication=database'),
        );
    }

    public function test_a_dsn_without_a_password_is_left_alone(): void
    {
        foreach (['postgresql://app@db/app', 'postgresql://db/app', 'host=db user=app'] as $dsn) {
            self::assertSame(['dsn' => $dsn, 'password' => null], ReplicationAccessInspector::withoutPassword($dsn));
        }
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
