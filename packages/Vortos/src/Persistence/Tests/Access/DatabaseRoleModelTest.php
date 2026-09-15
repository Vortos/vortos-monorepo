<?php

declare(strict_types=1);

namespace Vortos\Persistence\Tests\Access;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Persistence\Access\DatabaseRoleModel;
use Vortos\Persistence\Access\DatabaseRoleName;
use Vortos\Persistence\Access\DeclaredDatabaseRoles;
use Vortos\Persistence\DependencyInjection\VortosPersistenceConfig;

final class DatabaseRoleModelTest extends TestCase
{
    private static function model(?string $backup = 'app_backup', array $modules = ['Backup', 'Scheduler'], string $runtime = 'app_runtime'): DatabaseRoleModel
    {
        return new DatabaseRoleModel(
            database: 'app',
            schemas: ['public', 'vortos'],
            bootstrapSuperuser: new DatabaseRoleName('boot'),
            owner: new DatabaseRoleName('app_owner'),
            runtime: new DatabaseRoleName($runtime),
            backup: $backup === null ? null : new DatabaseRoleName($backup),
            backupWritableModules: $modules,
        );
    }

    public function test_it_round_trips_through_the_container_parameter_shape(): void
    {
        $model = self::model();

        self::assertEquals($model, DatabaseRoleModel::fromArray($model->toArray()));
        self::assertEquals($model, (new DeclaredDatabaseRoles($model->toArray()))->model());
        self::assertNull((new DeclaredDatabaseRoles(null))->model());
    }

    public function test_the_config_carries_the_declaration_and_none_by_default(): void
    {
        $config = new VortosPersistenceConfig();
        self::assertNull($config->toArray()['database_roles']);

        $config->databaseRoles(self::model());
        self::assertSame(self::model()->toArray(), $config->toArray()['database_roles']);
    }

    public function test_one_role_serving_two_audiences_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('four distinct roles');

        self::model(runtime: 'app_owner');
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRoleNames(): iterable
    {
        yield 'predefined prefix' => ['pg_monitor'];
        yield 'uppercase' => ['App'];
        yield 'quote' => ['app"; DROP ROLE x; --'];
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('a', 64)];
    }

    #[DataProvider('invalidRoleNames')]
    public function test_role_names_that_could_carry_sql_or_shadow_predefined_roles_are_refused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DatabaseRoleName($name);
    }

    public function test_writable_modules_without_a_backup_role_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('without a backup role');

        self::model(backup: null, modules: ['Backup']);
    }

    public function test_no_backup_role_is_an_explicit_null(): void
    {
        $model = self::model(backup: null, modules: []);

        self::assertNull($model->backup);
        self::assertEquals($model, DatabaseRoleModel::fromArray($model->toArray()));

        $data = $model->toArray();
        unset($data['backup']);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"backup"');
        DatabaseRoleModel::fromArray($data);
    }

    public function test_schemas_and_modules_are_validated(): void
    {
        foreach ([[[], ['Backup']], [['vortos', 'vortos'], ['Backup']], [['pg_catalog'], ['Backup']], [['vortos'], ['backup']], [['vortos'], ['Backup', 'Backup']]] as [$schemas, $modules]) {
            try {
                new DatabaseRoleModel('app', $schemas, new DatabaseRoleName('boot'), new DatabaseRoleName('o'), new DatabaseRoleName('r'), new DatabaseRoleName('b'), $modules);
                self::fail(sprintf('accepted schemas [%s] modules [%s]', implode(',', $schemas), implode(',', $modules)));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
