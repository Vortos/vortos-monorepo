<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Access\DatabaseRoleConvergenceSql;

/**
 * The rendered convergence SQL. Its behaviour on a real PostgreSQL 18 is proven by
 * {@see \Vortos\Migration\Tests\Integration\DatabaseRoleConvergenceIntegrationTest}; this pins the shape the
 * operator reviews and the properties that must hold for every model: exact attributes, no password text, a
 * commit per ownership change, and one atomic owner-tier transaction.
 */
final class DatabaseRoleConvergenceSqlTest extends TestCase
{
    private const VERIFIER = 'SCRAM-SHA-256$4096:c2FsdHNhbHRzYWx0c2FsdA==$c3RvcmVka2V5c3RvcmVka2V5c3RvcmVka2V5c3RvcmU=:c2VydmVya2V5c2VydmVya2V5c2VydmVya2V5c2VydmU=';

    /** @param array<string, string> $verifiers */
    private static function superuser(array $verifiers = [], bool $clearBootstrap = false): array
    {
        return (new DatabaseRoleConvergenceSql())->superuserStatements(RoleModelFixture::model(), $verifiers, $clearBootstrap);
    }

    public function test_the_superuser_tier_sets_exact_attributes_for_every_audience(): void
    {
        $statements = self::superuser();

        foreach ([
            "SET lock_timeout = '2s'",
            'ALTER ROLE "app_owner" WITH NOSUPERUSER NOREPLICATION NOCREATEROLE NOCREATEDB NOBYPASSRLS LOGIN INHERIT',
            'ALTER ROLE "app_runtime" WITH NOSUPERUSER NOREPLICATION NOCREATEROLE NOCREATEDB NOBYPASSRLS LOGIN INHERIT',
            'ALTER ROLE "app_backup" WITH NOSUPERUSER REPLICATION NOCREATEROLE NOCREATEDB BYPASSRLS LOGIN INHERIT',
            'GRANT "pg_checkpoint", "pg_monitor", "pg_read_all_data", "pg_read_all_settings" TO "app_backup"',
            'GRANT SELECT ON pg_catalog.pg_file_settings TO "app_backup"',
            'GRANT EXECUTE ON FUNCTION pg_catalog.pg_show_all_file_settings() TO "app_backup"',
            'ALTER DATABASE "app" OWNER TO "app_owner"',
        ] as $expected) {
            self::assertContains($expected, $statements);
        }

        $sql = implode("\n", $statements);
        self::assertStringContainsString('GRANTED BY %I', $sql);
        self::assertStringNotContainsString('PASSWORD', $sql);
        self::assertStringNotContainsString('"boot" WITH', $sql, 'the bootstrap superuser is never altered by the attribute convergence');
        foreach ($statements as $statement) {
            self::assertStringEndsNotWith(';', $statement, 'statements are printed and executed one at a time; the terminator is added by the printer');
        }
    }

    public function test_ownership_moves_one_object_per_transaction_with_bounded_lock_waits(): void
    {
        $statements = self::superuser();
        $loop = (string) end($statements);

        self::assertStringStartsWith('DO $vortos$', $loop);
        self::assertStringEndsWith('$vortos$', $loop);
        self::assertStringContainsString('EXCEPTION WHEN lock_not_available THEN', $loop);
        self::assertStringContainsString('COMMIT;', $loop);
        self::assertStringContainsString("c.relowner <> 'app_owner'::regrole", $loop);
        self::assertStringContainsString("'{public,vortos}'::text[]", $loop);
    }

    public function test_passwords_are_accepted_only_as_verifiers(): void
    {
        $statements = self::superuser(['app_runtime' => self::VERIFIER], true);

        self::assertContains("ALTER ROLE \"app_runtime\" PASSWORD '" . self::VERIFIER . "'", $statements);
        self::assertContains('ALTER ROLE "boot" PASSWORD NULL', $statements);
        self::assertSame(1, substr_count(implode("\n", $statements), 'SCRAM-SHA-256'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('never a password');
        self::superuser(['app_runtime' => "hunter2'; ALTER ROLE app_runtime SUPERUSER; --"]);
    }

    public function test_the_owner_tier_revokes_and_regrants_exactly(): void
    {
        $statements = (new DatabaseRoleConvergenceSql())->ownerStatements(RoleModelFixture::model(), ['vortos.backup_catalog']);

        self::assertSame("SET LOCAL lock_timeout = '5s'", $statements[0]);
        foreach ([
            'REVOKE ALL ON DATABASE "app" FROM PUBLIC',
            'GRANT CONNECT ON DATABASE "app" TO "app_runtime"',
            'GRANT CONNECT ON DATABASE "app" TO "app_backup"',
            'REVOKE ALL ON SCHEMA "vortos" FROM PUBLIC, "app_runtime", "app_backup"',
            'GRANT USAGE ON SCHEMA "vortos" TO "app_runtime", "app_backup"',
            'REVOKE ALL ON ALL TABLES IN SCHEMA "public" FROM PUBLIC, "app_runtime", "app_backup"',
            'GRANT DELETE, INSERT, SELECT, UPDATE ON ALL TABLES IN SCHEMA "public" TO "app_runtime"',
            'GRANT SELECT, UPDATE, USAGE ON ALL SEQUENCES IN SCHEMA "vortos" TO "app_runtime"',
            'ALTER DEFAULT PRIVILEGES FOR ROLE "app_owner" IN SCHEMA "vortos" GRANT DELETE, INSERT, SELECT, UPDATE ON TABLES TO "app_runtime"',
            'ALTER DEFAULT PRIVILEGES FOR ROLE "app_owner" IN SCHEMA "public" GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO "app_runtime"',
        ] as $expected) {
            self::assertContains($expected, $statements);
        }

        // Every revoke precedes its grant, so the single transaction ends in exactly the model.
        self::assertLessThan(
            array_search('GRANT DELETE, INSERT, SELECT, UPDATE ON ALL TABLES IN SCHEMA "public" TO "app_runtime"', $statements, true),
            array_search('REVOKE ALL ON ALL TABLES IN SCHEMA "public" FROM PUBLIC, "app_runtime", "app_backup"', $statements, true),
        );

        $backupGrant = (string) end($statements);
        self::assertStringContainsString("'{vortos.backup_catalog}'::text[]", $backupGrant);
        self::assertStringContainsString('ON TABLE %s TO %I', $backupGrant);
        self::assertStringContainsString('ON SEQUENCE %s TO %I', $backupGrant);
        self::assertStringNotContainsString('TRUNCATE', implode("\n", $statements));
    }

    public function test_the_owner_tier_without_a_backup_role_names_no_backup_grantee(): void
    {
        $statements = (new DatabaseRoleConvergenceSql())->ownerStatements(RoleModelFixture::model(backup: null), []);

        self::assertStringNotContainsString('app_backup', implode("\n", $statements));
        self::assertContains('REVOKE ALL ON SCHEMA "vortos" FROM PUBLIC, "app_runtime"', $statements);
    }

    public function test_a_table_name_that_is_not_a_plain_identifier_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new DatabaseRoleConvergenceSql())->ownerStatements(RoleModelFixture::model(), ["vortos.x'); DROP TABLE y; --"]);
    }
}
