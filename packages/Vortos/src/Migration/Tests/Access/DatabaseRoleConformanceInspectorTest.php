<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Access\DatabaseRoleConformanceInspector;
use Vortos\Migration\Access\DatabaseRoleConformanceReport;
use Vortos\Migration\Access\DatabaseRoleConformanceStatus;
use Vortos\Migration\Access\DatabaseRoleViolation;
use Vortos\Migration\Health\DatabaseRoleConformanceProbe;
use Vortos\Health\Probe\ProbeStatus;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;

/**
 * The live database held to the declared role model in both directions — including the single-superuser shape
 * measured on production on 2026-09-15, under which row-level security was not enforced at all.
 */
final class DatabaseRoleConformanceInspectorTest extends TestCase
{
    /** @param array<string, mixed> $snapshot */
    private static function inspect(array $snapshot, ?DatabaseRoleModel $model = null, ?\Throwable $readerThrows = null, ?\Throwable $tablesThrow = null): DatabaseRoleConformanceReport
    {
        $model ??= RoleModelFixture::model();

        return (new DatabaseRoleConformanceInspector(
            RoleModelFixture::reader($snapshot, $readerThrows),
            RoleModelFixture::tables(throws: $tablesThrow),
            new DeclaredDatabaseRoles($model->toArray()),
        ))->inspect();
    }

    /** @return list<string> "kind role subject: detail" */
    private static function lines(DatabaseRoleConformanceReport $report): array
    {
        return array_map(static fn (DatabaseRoleViolation $v): string => sprintf('%s %s %s: %s', $v->kind->value, $v->role, $v->subject, $v->detail), $report->violations);
    }

    public function test_a_database_holding_exactly_the_model_conforms(): void
    {
        $report = self::inspect(RoleModelFixture::conforming());

        self::assertSame([], self::lines($report));
        self::assertSame(DatabaseRoleConformanceStatus::Conforming, $report->status);
        self::assertTrue($report->connectionsChecked);
    }

    /** Production before the roles existed: one SUPERUSER + BYPASSRLS role owning everything, every client on it. */
    public function test_the_single_superuser_production_database_violates_on_every_count(): void
    {
        $all = ['superuser' => true, 'replication' => true, 'createrole' => true, 'createdb' => true, 'bypassrls' => true, 'login' => true];
        $snapshot = [
            'currentDatabase' => 'app',
            'roles' => ['boot' => $all],
            'memberships' => [],
            'databaseOwner' => 'boot',
            'databasePrivileges' => ['PUBLIC' => ['CONNECT', 'TEMPORARY']],
            'schemas' => [
                'public' => ['owner' => 'pg_database_owner', 'privileges' => ['PUBLIC' => ['USAGE']]],
                'vortos' => ['owner' => 'boot', 'privileges' => []],
            ],
            'relations' => [
                RoleModelFixture::relation('vortos', 'audit_events', 'table', [], owner: 'boot'),
                RoleModelFixture::relation('vortos', 'search_documents', 'table', [], owner: 'boot'),
            ],
            'routinesAndTypes' => [],
            'ownerDefaultPrivileges' => ['public' => ['tables' => [], 'sequences' => []], 'vortos' => ['tables' => [], 'sequences' => []]],
            'backupCatalogAccess' => null,
            'superuserClientConnections' => 15,
        ];

        $report = self::inspect($snapshot);
        $lines = self::lines($report);

        self::assertSame(DatabaseRoleConformanceStatus::Violated, $report->status);
        foreach ([
            'role_missing app_owner owner role: does not exist',
            'role_missing app_runtime runtime role: does not exist',
            'role_missing app_backup backup role: does not exist',
            'ownership boot database app: owns it, the owner role is app_owner',
            'excess_privilege PUBLIC database app: has CONNECT, TEMPORARY',
            'missing_privilege app_runtime database app: lacks CONNECT',
            'ownership boot schema public: owns it, the owner role is app_owner',
            'excess_privilege PUBLIC schema public: has USAGE',
            'ownership boot schema vortos: owns it, the owner role is app_owner',
            'ownership boot 2 tables (vortos.audit_events, vortos.search_documents): owns it, the owner role is app_owner',
            'missing_privilege app_runtime 2 tables (vortos.audit_events, vortos.search_documents): lacks DELETE, INSERT, SELECT, UPDATE',
            'default_privileges app_runtime future tables the owner creates in schema vortos: lacks DELETE, INSERT, SELECT, UPDATE',
            'superuser_client_connection * pg_stat_activity: 15 network client session(s) connected as a superuser',
        ] as $expected) {
            self::assertContains($expected, $lines);
        }
    }

    public function test_an_escalated_runtime_role_is_named_attribute_by_attribute(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['roles']['app_runtime']['superuser'] = true;
        $snapshot['roles']['app_runtime']['bypassrls'] = true;

        self::assertSame([
            'role_attribute app_runtime runtime role: is SUPERUSER, must be NOSUPERUSER',
            'role_attribute app_runtime runtime role: is BYPASSRLS, must be NOBYPASSRLS',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_an_object_the_runtime_role_created_is_an_ownership_violation(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['relations'][] = RoleModelFixture::relation('vortos', 'shadow', 'table', [], owner: 'app_runtime');
        $snapshot['routinesAndTypes'][] = ['schema' => 'vortos', 'name' => 'status', 'kind' => 'type', 'owner' => 'app_runtime'];

        self::assertSame([
            'ownership app_runtime table vortos.shadow: owns it, the owner role is app_owner',
            'missing_privilege app_runtime table vortos.shadow: lacks DELETE, INSERT, SELECT, UPDATE',
            'ownership app_runtime type vortos.status: owns it, the owner role is app_owner',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_the_backup_role_may_write_only_its_modules_tables(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['relations'][0]['privileges']['app_backup'] = RoleModelFixture::DML;
        $snapshot['relations'][2]['privileges']['app_backup'] = ['SELECT'];

        self::assertSame([
            'excess_privilege app_backup table vortos.audit_events: has DELETE, INSERT, SELECT, UPDATE',
            'missing_privilege app_backup table vortos.backup_catalog: lacks DELETE, INSERT, UPDATE',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_truncate_and_public_grants_are_excess(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['relations'][4]['privileges']['app_runtime'][] = 'TRUNCATE';
        $snapshot['relations'][4]['privileges']['PUBLIC'] = ['SELECT'];
        $snapshot['schemas']['vortos']['privileges']['app_runtime'][] = 'CREATE';

        self::assertSame([
            'excess_privilege app_runtime schema vortos: has CREATE',
            'excess_privilege PUBLIC table public.registration_payment_ledger: has SELECT',
            'excess_privilege app_runtime table public.registration_payment_ledger: has TRUNCATE',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_undeclared_roles_memberships_and_default_privileges(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['roles']['legacy_app'] = RoleModelFixture::conforming()['roles']['app_runtime'];
        $snapshot['roles']['break_glass'] = ['login' => false, 'superuser' => true] + RoleModelFixture::conforming()['roles']['app_runtime'];
        $snapshot['roles']['reporting'] = ['login' => false] + RoleModelFixture::conforming()['roles']['app_runtime'];
        $snapshot['memberships']['app_runtime'] = ['app_owner'];
        $snapshot['memberships']['app_backup'] = ['pg_monitor', 'pg_read_all_data', 'pg_read_all_settings'];
        $snapshot['ownerDefaultPrivileges']['public']['sequences'] = [];

        self::assertSame([
            'role_membership app_runtime membership app_owner: is a member, must not be',
            'role_membership app_backup membership pg_checkpoint: is not a member, must be',
            'undeclared_role legacy_app undeclared role: can log in',
            'undeclared_role break_glass undeclared role: is SUPERUSER',
            'default_privileges app_runtime future sequences the owner creates in schema public: lacks SELECT, UPDATE, USAGE',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_the_config_drift_probe_catalog_grants_are_required(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['backupCatalogAccess'] = ['fileSettingsView' => true, 'fileSettingsFunction' => false];

        self::assertSame(
            ['missing_privilege app_backup function pg_catalog.pg_show_all_file_settings(): lacks EXECUTE'],
            self::lines(self::inspect($snapshot)),
        );
    }

    public function test_a_bootstrap_role_that_lost_superuser_and_a_public_schema_of_another_owner(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['roles']['boot']['superuser'] = false;
        $snapshot['databaseOwner'] = 'boot';

        self::assertSame([
            'role_attribute boot bootstrap role: is NOSUPERUSER, must be SUPERUSER',
            'ownership boot database app: owns it, the owner role is app_owner',
            'ownership boot schema public: owns it, the owner role is app_owner',
        ], self::lines(self::inspect($snapshot)));
    }

    public function test_many_objects_are_reported_once_with_a_sample(): void
    {
        $snapshot = RoleModelFixture::conforming();
        foreach (range(1, 7) as $i) {
            $snapshot['relations'][] = RoleModelFixture::relation('public', 't' . $i, 'table', ['app_runtime' => RoleModelFixture::DML], owner: 'boot');
        }

        self::assertSame(
            ['ownership boot 7 tables (public.t1, public.t2, public.t3, public.t4, public.t5, …): owns it, the owner role is app_owner'],
            self::lines(self::inspect($snapshot)),
        );
    }

    public function test_sessions_this_role_cannot_see_are_reported_as_unchecked_not_as_zero(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['superuserClientConnections'] = null;

        $report = self::inspect($snapshot);

        self::assertSame(DatabaseRoleConformanceStatus::Conforming, $report->status);
        self::assertFalse($report->connectionsChecked);
        self::assertFalse($report->toDetail()['connections_checked']);
    }

    public function test_without_a_backup_role_nothing_about_backup_is_demanded(): void
    {
        $snapshot = RoleModelFixture::conforming();
        unset($snapshot['roles']['app_backup'], $snapshot['memberships']['app_backup'], $snapshot['databasePrivileges']['app_backup']);
        unset($snapshot['schemas']['public']['privileges']['app_backup'], $snapshot['schemas']['vortos']['privileges']['app_backup']);
        unset($snapshot['relations'][2]['privileges']['app_backup'], $snapshot['relations'][3]['privileges']['app_backup']);
        $snapshot['backupCatalogAccess'] = null;

        self::assertSame([], self::lines(self::inspect($snapshot, RoleModelFixture::model(backup: null))));
    }

    public function test_a_connection_to_another_database_is_indeterminate(): void
    {
        $snapshot = RoleModelFixture::conforming();
        $snapshot['currentDatabase'] = 'postgres';

        $report = self::inspect($snapshot);

        self::assertSame(DatabaseRoleConformanceStatus::Indeterminate, $report->status);
        self::assertSame('connected to database "postgres", the role model governs "app"', $report->reason);
    }

    public function test_a_failed_read_is_indeterminate_and_never_echoes_the_error_message(): void
    {
        $report = self::inspect([], readerThrows: new \RuntimeException('SQLSTATE[08006] could not connect to pgsql://app_runtime:s3cr3t@write_db/app'));

        self::assertSame(DatabaseRoleConformanceStatus::Indeterminate, $report->status);
        self::assertSame(\RuntimeException::class, $report->reason);
        self::assertStringNotContainsString('s3cr3t', (string) json_encode($report->toDetail()));
    }

    public function test_a_module_that_creates_no_table_is_indeterminate_rather_than_granting_nothing(): void
    {
        $report = self::inspect(RoleModelFixture::conforming(), tablesThrow: new \InvalidArgumentException('Module "Bakup" …'));

        self::assertSame(DatabaseRoleConformanceStatus::Indeterminate, $report->status);
    }

    public function test_undeclared_is_its_own_status(): void
    {
        $report = (new DatabaseRoleConformanceInspector(RoleModelFixture::reader([]), RoleModelFixture::tables(), new DeclaredDatabaseRoles(null)))->inspect();
        self::assertSame(DatabaseRoleConformanceStatus::Undeclared, $report->status);

        $report = (new DatabaseRoleConformanceInspector(RoleModelFixture::reader([]), RoleModelFixture::tables(), null))->inspect();
        self::assertSame(DatabaseRoleConformanceStatus::Undeclared, $report->status);
    }

    public function test_the_probe_pages_only_on_violation(): void
    {
        $probe = static fn (array $snapshot, ?DeclaredDatabaseRoles $declared): ProbeStatus => (new DatabaseRoleConformanceProbe(
            new DatabaseRoleConformanceInspector(RoleModelFixture::reader($snapshot), RoleModelFixture::tables(), $declared),
        ))->check()->status;
        $declared = new DeclaredDatabaseRoles(RoleModelFixture::model()->toArray());

        $violated = RoleModelFixture::conforming();
        $violated['roles']['app_runtime']['superuser'] = true;
        $elsewhere = RoleModelFixture::conforming();
        $elsewhere['currentDatabase'] = 'postgres';

        self::assertSame(ProbeStatus::Pass, $probe(RoleModelFixture::conforming(), $declared));
        self::assertSame(ProbeStatus::Fail, $probe($violated, $declared));
        self::assertSame(ProbeStatus::Warn, $probe($elsewhere, $declared));
        self::assertSame(ProbeStatus::Warn, $probe([], new DeclaredDatabaseRoles(null)));
        self::assertSame(DatabaseRoleConformanceProbe::NAME, 'database-role-conformance');
    }
}
