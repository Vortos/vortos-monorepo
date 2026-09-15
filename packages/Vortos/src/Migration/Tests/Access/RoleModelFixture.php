<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Access;

use Vortos\Migration\Access\ModuleTableResolverInterface;
use Vortos\Migration\Access\RoleCatalogReaderInterface;
use Vortos\Migration\Access\RoleCatalogSnapshot;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;

/** A declared model and the catalog snapshot of a database that holds it exactly. */
final class RoleModelFixture
{
    public const DML = ['DELETE', 'INSERT', 'SELECT', 'UPDATE'];
    public const SEQ = ['SELECT', 'UPDATE', 'USAGE'];

    public static function model(?string $backup = 'app_backup', string $bootstrap = 'boot'): DatabaseRoleModel
    {
        return new DatabaseRoleModel(
            database: 'app',
            schemas: ['public', 'vortos'],
            bootstrapSuperuser: new DatabaseRoleName($bootstrap),
            owner: new DatabaseRoleName('app_owner'),
            runtime: new DatabaseRoleName('app_runtime'),
            backup: $backup === null ? null : new DatabaseRoleName($backup),
            backupWritableModules: $backup === null ? [] : ['Backup'],
        );
    }

    /** @return array<string, mixed> named constructor arguments of a conforming {@see RoleCatalogSnapshot} */
    public static function conforming(): array
    {
        $login = ['superuser' => false, 'replication' => false, 'createrole' => false, 'createdb' => false, 'bypassrls' => false, 'login' => true];

        return [
            'currentDatabase' => 'app',
            'roles' => [
                'app_backup' => ['replication' => true, 'bypassrls' => true] + $login,
                'app_owner' => $login,
                'app_runtime' => $login,
                'boot' => ['superuser' => true, 'replication' => true, 'createrole' => true, 'createdb' => true, 'bypassrls' => true, 'login' => true],
            ],
            'memberships' => ['app_backup' => ['pg_checkpoint', 'pg_monitor', 'pg_read_all_data', 'pg_read_all_settings']],
            'databaseOwner' => 'app_owner',
            'databasePrivileges' => ['app_backup' => ['CONNECT'], 'app_runtime' => ['CONNECT']],
            'schemas' => [
                'public' => ['owner' => 'pg_database_owner', 'privileges' => ['app_backup' => ['USAGE'], 'app_runtime' => ['USAGE']]],
                'vortos' => ['owner' => 'app_owner', 'privileges' => ['app_backup' => ['USAGE'], 'app_runtime' => ['USAGE']]],
            ],
            'relations' => [
                self::relation('vortos', 'audit_events', 'table', ['app_runtime' => self::DML]),
                self::relation('vortos', 'audit_events_id_seq', 'sequence', ['app_runtime' => self::SEQ], 'vortos.audit_events'),
                self::relation('vortos', 'backup_catalog', 'table', ['app_backup' => self::DML, 'app_runtime' => self::DML]),
                self::relation('vortos', 'backup_catalog_id_seq', 'sequence', ['app_backup' => self::SEQ, 'app_runtime' => self::SEQ], 'vortos.backup_catalog'),
                self::relation('public', 'registration_payment_ledger', 'table', ['app_runtime' => self::DML]),
            ],
            'routinesAndTypes' => [
                ['schema' => 'public', 'name' => 'ledger_immutable()', 'kind' => 'function', 'owner' => 'app_owner'],
            ],
            'ownerDefaultPrivileges' => [
                'public' => ['tables' => ['app_runtime' => self::DML], 'sequences' => ['app_runtime' => self::SEQ]],
                'vortos' => ['tables' => ['app_runtime' => self::DML], 'sequences' => ['app_runtime' => self::SEQ]],
            ],
            'backupCatalogAccess' => ['fileSettingsView' => true, 'fileSettingsFunction' => true],
            'superuserClientConnections' => 0,
        ];
    }

    /**
     * @param array<string, list<string>> $privileges
     * @return array{schema: string, name: string, kind: string, owner: string, privileges: array<string, list<string>>, ownedBy: string|null}
     */
    public static function relation(string $schema, string $name, string $kind, array $privileges, ?string $ownedBy = null, string $owner = 'app_owner'): array
    {
        return ['schema' => $schema, 'name' => $name, 'kind' => $kind, 'owner' => $owner, 'privileges' => $privileges, 'ownedBy' => $ownedBy];
    }

    /** @param array<string, mixed> $arguments */
    public static function reader(array $arguments, ?\Throwable $throws = null): RoleCatalogReaderInterface
    {
        return new class ($arguments, $throws) implements RoleCatalogReaderInterface {
            public function __construct(private array $arguments, private ?\Throwable $throws) {}

            public function read(DatabaseRoleModel $model): RoleCatalogSnapshot
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return new RoleCatalogSnapshot(...$this->arguments);
            }
        };
    }

    /** @param list<string> $tables */
    public static function tables(array $tables = ['vortos.backup_catalog'], ?\Throwable $throws = null): ModuleTableResolverInterface
    {
        return new class ($tables, $throws) implements ModuleTableResolverInterface {
            public function __construct(private array $tables, private ?\Throwable $throws) {}

            public function tablesOf(array $modules): array
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }

                return $modules === [] ? [] : $this->tables;
            }
        };
    }
}
