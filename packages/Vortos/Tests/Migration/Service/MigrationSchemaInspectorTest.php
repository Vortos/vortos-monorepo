<?php

declare(strict_types=1);

namespace Vortos\Tests\Migration\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use PHPUnit\Framework\TestCase;
use Vortos\Migration\Schema\MigrationOwnership;
use Vortos\Migration\Schema\ModuleMigrationDescriptor;
use Vortos\Migration\Service\MigrationSchemaInspector;

final class MigrationSchemaInspectorTest extends TestCase
{
    private function makeDescriptor(array $tables, array $indexes = []): ModuleMigrationDescriptor
    {
        return new ModuleMigrationDescriptor(
            source:    'test.sql',
            class:     'App\\Migrations\\TestVersion',
            module:    'Test',
            filename:  'test.sql',
            ownership: new MigrationOwnership($tables, $indexes),
            provider:  null,
        );
    }

    private function makeConnection(array $tableNames, bool $isPostgres = false): Connection
    {
        $platform = $isPostgres
            ? $this->createMock(PostgreSQLPlatform::class)
            : $this->createMock(AbstractPlatform::class);

        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn($tableNames);
        $schemaManager->method('listTableIndexes')->willReturn([]);
        $schemaManager->method('listTableColumns')->willReturn([]);

        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($schemaManager);
        $conn->method('fetchFirstColumn')->willReturn([]);

        return $conn;
    }

    public function test_existing_tables_returns_tables_present_in_db(): void
    {
        $conn = $this->makeConnection(['vortos_user_roles', 'vortos_role_permissions', 'orders']);
        $inspector = new MigrationSchemaInspector($conn);

        $descriptor = $this->makeDescriptor(['vortos_user_roles', 'vortos_role_permissions']);

        $this->assertSame(['vortos_role_permissions', 'vortos_user_roles'], $inspector->existingTables($descriptor));
    }

    public function test_missing_tables_returns_tables_absent_from_db(): void
    {
        $conn = $this->makeConnection(['vortos_user_roles']);
        $inspector = new MigrationSchemaInspector($conn);

        $descriptor = $this->makeDescriptor(['vortos_user_roles', 'vortos_role_permissions']);

        $this->assertSame(['vortos_role_permissions'], $inspector->missingTables($descriptor));
    }

    public function test_table_name_comparison_is_case_insensitive(): void
    {
        $conn = $this->makeConnection(['Vortos_User_Roles']);
        $inspector = new MigrationSchemaInspector($conn);

        $descriptor = $this->makeDescriptor(['vortos_user_roles']);

        $this->assertSame(['vortos_user_roles'], $inspector->existingTables($descriptor));
        $this->assertSame([], $inspector->missingTables($descriptor));
    }

    public function test_postgres_augments_index_with_schema_qualified_names(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['orders']);
        $schemaManager->method('listTableIndexes')->willReturn([]);

        $platform = $this->createMock(PostgreSQLPlatform::class);

        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($schemaManager);
        $conn->method('fetchFirstColumn')->willReturn(['vortos.user_roles', 'vortos.role_permissions']);

        $inspector = new MigrationSchemaInspector($conn);
        $descriptor = $this->makeDescriptor(['vortos.user_roles', 'vortos.role_permissions']);

        $this->assertSame(
            ['vortos.role_permissions', 'vortos.user_roles'],
            $inspector->existingTables($descriptor),
        );
    }

    public function test_postgres_schema_qualified_tables_are_detected_as_missing(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['orders']);

        $platform = $this->createMock(PostgreSQLPlatform::class);

        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($schemaManager);
        $conn->method('fetchFirstColumn')->willReturn([]);

        $inspector = new MigrationSchemaInspector($conn);
        $descriptor = $this->makeDescriptor(['vortos.user_roles']);

        $this->assertSame([], $inspector->existingTables($descriptor));
        $this->assertSame(['vortos.user_roles'], $inspector->missingTables($descriptor));
    }

    public function test_table_name_index_is_cached_across_calls(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->expects($this->once())->method('listTableNames')->willReturn(['orders']);

        $platform = $this->createMock(AbstractPlatform::class);

        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($schemaManager);
        $conn->method('fetchFirstColumn')->willReturn([]);

        $inspector = new MigrationSchemaInspector($conn);
        $descriptor = $this->makeDescriptor(['orders']);

        // Two calls — listTableNames must only be called once
        $inspector->existingTables($descriptor);
        $inspector->existingTables($descriptor);
    }

    public function test_non_postgres_does_not_query_information_schema(): void
    {
        $schemaManager = $this->createMock(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn(['vortos_user_roles']);

        $platform = $this->createMock(AbstractPlatform::class);

        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($schemaManager);
        $conn->expects($this->never())->method('fetchFirstColumn');

        $inspector = new MigrationSchemaInspector($conn);
        $descriptor = $this->makeDescriptor(['vortos_user_roles']);

        $inspector->existingTables($descriptor);
    }
}
