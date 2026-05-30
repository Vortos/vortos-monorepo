<?php

declare(strict_types=1);

namespace Vortos\Tests\Authorization\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Storage\DbalRolePermissionStore;

final class DbalRolePermissionStoreTest extends TestCase
{
    private const TABLE = 'role_permissions';

    private \Doctrine\DBAL\Connection $connection;
    private DbalRolePermissionStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (role VARCHAR(150) NOT NULL, permission VARCHAR(190) NOT NULL, PRIMARY KEY (role, permission))',
        );
        $this->store = new DbalRolePermissionStore($this->connection, self::TABLE);
    }

    public function test_uses_injected_table_name_in_queries(): void
    {
        $customTable = 'vortos_role_permissions';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $customTable . ' (role VARCHAR(150) NOT NULL, permission VARCHAR(190) NOT NULL, PRIMARY KEY (role, permission))',
        );

        $store = new DbalRolePermissionStore($this->connection, $customTable);
        $store->grant('ROLE_ADMIN', 'orders.read.any');

        $this->assertSame(['orders.read.any'], $store->permissionsForRole('ROLE_ADMIN'));
        $this->assertSame([], $this->store->permissionsForRole('ROLE_ADMIN'));
    }

    public function test_permissions_for_role_returns_empty_when_none(): void
    {
        $this->assertSame([], $this->store->permissionsForRole('ROLE_UNKNOWN'));
    }

    public function test_grant_persists_permission(): void
    {
        $this->store->grant('ROLE_SUPPORT', 'orders.refund.any');

        $this->assertSame(['orders.refund.any'], $this->store->permissionsForRole('ROLE_SUPPORT'));
    }

    public function test_grant_is_idempotent(): void
    {
        $this->store->grant('ROLE_SUPPORT', 'orders.refund.any');
        $this->store->grant('ROLE_SUPPORT', 'orders.refund.any');

        $this->assertCount(1, $this->store->permissionsForRole('ROLE_SUPPORT'));
    }

    public function test_revoke_removes_permission(): void
    {
        $this->store->grant('ROLE_SUPPORT', 'orders.refund.any');
        $this->store->revoke('ROLE_SUPPORT', 'orders.refund.any');

        $this->assertSame([], $this->store->permissionsForRole('ROLE_SUPPORT'));
    }

    public function test_permissions_for_roles_groups_by_role(): void
    {
        $this->store->grant('ROLE_SUPPORT', 'orders.refund.any');
        $this->store->grant('ROLE_ADMIN', 'orders.delete.any');
        $this->store->grant('ROLE_ADMIN', 'orders.refund.any');

        $result = $this->store->permissionsForRoles(['ROLE_SUPPORT', 'ROLE_ADMIN', 'ROLE_GUEST']);

        $this->assertSame(['orders.refund.any'], $result['ROLE_SUPPORT']);
        $this->assertSame(['orders.delete.any', 'orders.refund.any'], $result['ROLE_ADMIN']);
        $this->assertSame([], $result['ROLE_GUEST']);
    }

    public function test_permissions_for_roles_with_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->store->permissionsForRoles([]));
    }
}
