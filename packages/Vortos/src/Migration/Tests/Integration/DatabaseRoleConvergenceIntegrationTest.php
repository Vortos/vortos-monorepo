<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Integration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Foundation\Secret\SecretValue;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConformanceStatus;
use Vortos\Migration\Access\DatabaseRoleConvergenceSql;
use Vortos\Migration\Access\DatabaseRoleViolation;
use Vortos\Migration\Access\PgRoleCatalogReader;
use Vortos\Migration\Access\ScramSha256Verifier;
use Vortos\Migration\Console\DatabaseRolesGrantCommand;
use Vortos\Migration\Tests\Access\RoleModelFixture;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * The least-privilege role model applied to a real PostgreSQL and proven in both directions: the rendered SQL
 * runs (twice — it is idempotent), the SCRAM verifiers log in, the runtime role is confined by FORCE ROW LEVEL
 * SECURITY and refused DDL/TRUNCATE/replication-class operations, the owner's later tables reach the runtime role
 * through default privileges, the backup role writes only its module's tables, and the inspector reports
 * conforming from every audience's connection — then catches a hand-applied escalation.
 *
 * DESTRUCTIVE: creates roles and moves ownership. Runs only when VORTOS_ROLES_IT_SUPERUSER_DSN names a superuser on
 * a throwaway cluster; the test database is always "vortos_roles_it", dropped and recreated.
 */
final class DatabaseRoleConvergenceIntegrationTest extends TestCase
{
    private const DATABASE = 'vortos_roles_it';

    private const PASSWORDS = [
        'it_owner' => 'owner-password-0123456789',
        'it_runtime' => 'runtime-password-0123456789',
        'it_backup' => 'backup-password-0123456789',
    ];

    /** @var array{host: string, port: int, user: string, password: string} */
    private array $server;

    private DatabaseRoleModel $model;

    protected function setUp(): void
    {
        $dsn = (string) (getenv('VORTOS_ROLES_IT_SUPERUSER_DSN') ?: '');
        if ($dsn === '') {
            self::markTestSkipped('VORTOS_ROLES_IT_SUPERUSER_DSN is not set (a superuser on a throwaway PostgreSQL cluster).');
        }
        $p = parse_url($dsn);
        $this->server = [
            'host' => (string) ($p['host'] ?? ''),
            'port' => (int) ($p['port'] ?? 5432),
            'user' => rawurldecode((string) ($p['user'] ?? '')),
            'password' => rawurldecode((string) ($p['pass'] ?? '')),
        ];

        $admin = $this->connect($this->server['user'], $this->server['password'], 'postgres');
        $admin->executeStatement('DROP DATABASE IF EXISTS ' . self::DATABASE . ' WITH (FORCE)');
        foreach (array_keys(self::PASSWORDS) as $role) {
            $admin->executeStatement(sprintf('DROP ROLE IF EXISTS %s', $role));
        }
        $admin->executeStatement('CREATE DATABASE ' . self::DATABASE);
        $admin->close();

        // Today's production shape: everything created by the bootstrap superuser.
        $boot = $this->bootstrap();
        foreach ([
            'CREATE SCHEMA vortos',
            'CREATE EXTENSION IF NOT EXISTS pg_trgm',
            'CREATE TABLE vortos.audit_events (id bigserial PRIMARY KEY, tenant_id text NOT NULL, body text)',
            "ALTER TABLE vortos.audit_events ENABLE ROW LEVEL SECURITY",
            "ALTER TABLE vortos.audit_events FORCE ROW LEVEL SECURITY",
            "CREATE POLICY audit_tenant_isolation ON vortos.audit_events USING (current_setting('app.current_tenant', true) IS NULL OR current_setting('app.current_tenant', true) = '' OR tenant_id = current_setting('app.current_tenant', true)) WITH CHECK (current_setting('app.current_tenant', true) IS NULL OR current_setting('app.current_tenant', true) = '' OR tenant_id = current_setting('app.current_tenant', true))",
            'CREATE TABLE vortos.backup_catalog (id bigserial PRIMARY KEY, store_key text NOT NULL)',
            'CREATE TABLE public.ledger (id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, amount integer NOT NULL)',
            "CREATE FUNCTION public.ledger_immutable() RETURNS trigger LANGUAGE plpgsql AS \$\$BEGIN RAISE EXCEPTION 'immutable'; END\$\$",
            'CREATE TRIGGER trg_ledger_immutable BEFORE UPDATE OR DELETE ON public.ledger FOR EACH ROW EXECUTE FUNCTION public.ledger_immutable()',
            'CREATE SEQUENCE public.reference_numbers',
            "CREATE TYPE vortos.lifecycle AS ENUM ('active', 'retired')",
            'CREATE VIEW public.ledger_totals AS SELECT sum(amount) AS total FROM public.ledger',
            "INSERT INTO vortos.audit_events (tenant_id, body) VALUES ('t1', 'a'), ('t2', 'b')",
            'INSERT INTO public.ledger (amount) VALUES (1)',
        ] as $statement) {
            $boot->executeStatement($statement);
        }
        $boot->close();

        $this->model = new DatabaseRoleModel(
            database: self::DATABASE,
            schemas: ['public', 'vortos'],
            bootstrapSuperuser: new DatabaseRoleName($this->server['user']),
            owner: new DatabaseRoleName('it_owner'),
            runtime: new DatabaseRoleName('it_runtime'),
            backup: new DatabaseRoleName('it_backup'),
            backupWritableModules: ['Backup'],
        );
    }

    public function test_the_model_converges_a_superuser_owned_database_and_holds_in_both_directions(): void
    {
        $before = $this->inspect($this->bootstrap());
        self::assertSame(DatabaseRoleConformanceStatus::Violated, $before->status);
        self::assertContains('role_missing it_runtime', array_map(static fn (DatabaseRoleViolation $v): string => $v->kind->value . ' ' . $v->role, $before->violations));

        $this->converge();
        $this->converge(); // idempotent

        $runtime = $this->connect('it_runtime', self::PASSWORDS['it_runtime']);
        $owner = $this->connect('it_owner', self::PASSWORDS['it_owner']);
        $backup = $this->connect('it_backup', self::PASSWORDS['it_backup']);

        // Row-level security is real for the runtime role: bound sessions are confined both ways.
        self::assertSame(2, (int) $runtime->fetchOne('SELECT count(*) FROM vortos.audit_events'));
        $runtime->executeStatement("SELECT set_config('app.current_tenant', 't1', false)");
        self::assertSame(1, (int) $runtime->fetchOne('SELECT count(*) FROM vortos.audit_events'));
        $this->assertRefused($runtime, "INSERT INTO vortos.audit_events (tenant_id, body) VALUES ('t2', 'cross-tenant')", 'row-level security');
        $runtime->executeStatement("INSERT INTO vortos.audit_events (tenant_id, body) VALUES ('t1', 'own')");
        $runtime->executeStatement("SELECT set_config('app.current_tenant', '', false)");
        $runtime->executeStatement('INSERT INTO public.ledger (amount) VALUES (2)');
        self::assertGreaterThan(0, (int) $runtime->fetchOne("SELECT nextval('public.reference_numbers')"));
        self::assertNotNull($runtime->fetchOne('SELECT total FROM public.ledger_totals'));

        // …and it can change nothing structural.
        $this->assertRefused($runtime, 'CREATE TABLE vortos.shadow (id int)', 'permission denied');
        $this->assertRefused($runtime, 'CREATE TABLE public.shadow (id int)', 'permission denied');
        $this->assertRefused($runtime, 'TRUNCATE public.ledger', 'permission denied');
        $this->assertRefused($runtime, 'ALTER TABLE public.ledger DISABLE TRIGGER trg_ledger_immutable', 'must be owner');
        $this->assertRefused($runtime, 'DROP POLICY audit_tenant_isolation ON vortos.audit_events', 'must be owner');
        $this->assertRefused($runtime, 'CHECKPOINT', 'permission denied');
        $this->assertRefused($runtime, 'SET ROLE it_owner', 'permission denied');

        // The owner migrates: DDL and trigger toggling work, and what it creates later reaches the runtime role.
        $owner->executeStatement('CREATE TABLE vortos.added_later (id bigserial PRIMARY KEY, v text)');
        $owner->executeStatement('ALTER TABLE public.ledger DISABLE TRIGGER trg_ledger_immutable');
        $owner->executeStatement('ALTER TABLE public.ledger ENABLE TRIGGER trg_ledger_immutable');
        $runtime->executeStatement("INSERT INTO vortos.added_later (v) VALUES ('x')");
        $this->assertRefused($owner, 'CREATE ROLE sneaky', 'permission denied');

        // The backup role reads everything (a dump must), writes only its module's tables, and wakes the checkpointer.
        self::assertSame(3, (int) $backup->fetchOne('SELECT count(*) FROM vortos.audit_events'));
        $backup->executeStatement("INSERT INTO vortos.backup_catalog (store_key) VALUES ('k1')");
        $this->assertRefused($backup, "INSERT INTO vortos.audit_events (tenant_id) VALUES ('t1')", 'permission denied');
        $this->assertRefused($backup, 'INSERT INTO public.ledger (amount) VALUES (3)', 'permission denied');
        $backup->executeStatement('CHECKPOINT');
        self::assertGreaterThanOrEqual(0, (int) $backup->fetchOne('SELECT count(*) FROM pg_file_settings'));

        // The owner-tier grants for the table added later run as the deploy runs them.
        $grant = new CommandTester(new DatabaseRolesGrantCommand($owner, new DatabaseRoleConvergenceSql(), RoleModelFixture::tables(['vortos.backup_catalog']), new DeclaredDatabaseRoles($this->model->toArray())));
        self::assertSame(0, $grant->execute([]));

        // Conforming from every audience's connection; only a pg_monitor member can count superuser sessions.
        foreach (['runtime' => $runtime, 'owner' => $owner, 'backup' => $backup] as $audience => $connection) {
            $report = $this->inspect($connection);
            self::assertSame([], array_map(static fn (DatabaseRoleViolation $v): string => sprintf('%s %s %s: %s', $v->kind->value, $v->role, $v->subject, $v->detail), $report->violations), $audience);
            self::assertSame(DatabaseRoleConformanceStatus::Conforming, $report->status, $audience);
            self::assertSame($audience === 'backup', $report->connectionsChecked, $audience);
        }

        // A hand-applied escalation is caught.
        $boot = $this->bootstrap();
        $boot->executeStatement('ALTER ROLE it_runtime BYPASSRLS');
        $boot->executeStatement('GRANT TRUNCATE ON public.ledger TO it_runtime');
        $boot->close();
        $lines = array_map(static fn (DatabaseRoleViolation $v): string => sprintf('%s %s %s: %s', $v->kind->value, $v->role, $v->subject, $v->detail), $this->inspect($backup)->violations);
        self::assertSame([
            'role_attribute it_runtime runtime role: is BYPASSRLS, must be NOBYPASSRLS',
            'excess_privilege it_runtime table public.ledger: has TRUNCATE',
        ], $lines);

        foreach ([$runtime, $owner, $backup] as $connection) {
            $connection->close();
        }
    }

    private function converge(): void
    {
        $verifiers = [];
        foreach (self::PASSWORDS as $role => $password) {
            $verifiers[$role] = ScramSha256Verifier::derive(SecretValue::fromString($password));
        }

        $sql = new DatabaseRoleConvergenceSql();
        $boot = $this->bootstrap();
        // One statement per call, in autocommit — exactly how psql runs the printed script.
        foreach ($sql->superuserStatements($this->model, $verifiers, false) as $statement) {
            $boot->executeStatement($statement);
        }
        $boot->transactional(function (Connection $c) use ($sql): void {
            foreach ($sql->ownerStatements($this->model, ['vortos.backup_catalog']) as $statement) {
                $c->executeStatement($statement);
            }
        });
        $boot->close();
    }

    private function inspect(Connection $connection): \Vortos\Migration\Access\DatabaseRoleConformanceReport
    {
        return (new DatabaseRoleConformanceInspector(
            new PgRoleCatalogReader($connection),
            RoleModelFixture::tables(['vortos.backup_catalog']),
            new DeclaredDatabaseRoles($this->model->toArray()),
        ))->inspect();
    }

    private function assertRefused(Connection $connection, string $sql, string $message): void
    {
        try {
            $connection->executeStatement($sql);
            self::fail(sprintf('Expected refusal of: %s', $sql));
        } catch (\Doctrine\DBAL\Exception $e) {
            self::assertStringContainsString($message, $e->getMessage(), $sql);
        }
    }

    private function bootstrap(): Connection
    {
        return $this->connect($this->server['user'], $this->server['password']);
    }

    private function connect(string $user, string $password, string $database = self::DATABASE): Connection
    {
        return DriverManager::getConnection([
            'driver' => 'pdo_pgsql',
            'host' => $this->server['host'],
            'port' => $this->server['port'],
            'user' => $user,
            'password' => $password,
            'dbname' => $database,
        ]);
    }
}
