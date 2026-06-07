<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests\Storage;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Vortos\Authorization\Storage\DbalUserRoleStore;

final class DbalUserRoleStoreTest extends TestCase
{
    private const TABLE = 'user_roles';

    private \Doctrine\DBAL\Connection $connection;
    private DbalUserRoleStore $store;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            'CREATE TABLE ' . self::TABLE . ' (user_id VARCHAR(190) NOT NULL, role VARCHAR(150) NOT NULL, PRIMARY KEY (user_id, role))',
        );
        $this->store = new DbalUserRoleStore($this->connection, self::TABLE);
    }

    public function test_uses_injected_table_name_in_queries(): void
    {
        $customTable = 'vortos_user_roles';
        $this->connection->executeStatement(
            'CREATE TABLE ' . $customTable . ' (user_id VARCHAR(190) NOT NULL, role VARCHAR(150) NOT NULL, PRIMARY KEY (user_id, role))',
        );

        $store = new DbalUserRoleStore($this->connection, $customTable);
        $store->assignRole('user-1', 'ROLE_ADMIN');

        $this->assertSame(['ROLE_ADMIN'], $store->rolesForUser('user-1'));
        $this->assertSame([], $this->store->rolesForUser('user-1'));
    }

    public function test_roles_for_user_returns_empty_when_no_roles(): void
    {
        $this->assertSame([], $this->store->rolesForUser('user-99'));
    }

    public function test_assign_role_persists_and_is_returned(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');

        $this->assertSame(['ROLE_SUPPORT'], $this->store->rolesForUser('user-1'));
    }

    public function test_assign_role_is_idempotent(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');

        $this->assertCount(1, $this->store->rolesForUser('user-1'));
    }

    public function test_remove_role_deletes_the_assignment(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');
        $this->store->removeRole('user-1', 'ROLE_SUPPORT');

        $this->assertSame([], $this->store->rolesForUser('user-1'));
    }

    public function test_remove_role_on_non_existent_is_a_noop(): void
    {
        $this->store->removeRole('user-99', 'ROLE_SUPPORT');
        $this->addToAssertionCount(1);
    }

    public function test_users_for_role_returns_matching_users(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');
        $this->store->assignRole('user-2', 'ROLE_SUPPORT');
        $this->store->assignRole('user-3', 'ROLE_ADMIN');

        $users = $this->store->usersForRole('ROLE_SUPPORT', 10, 0);

        $this->assertSame(['user-1', 'user-2'], $users);
    }

    public function test_users_for_role_respects_limit_and_offset(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');
        $this->store->assignRole('user-2', 'ROLE_SUPPORT');
        $this->store->assignRole('user-3', 'ROLE_SUPPORT');

        $this->assertSame(['user-2'], $this->store->usersForRole('ROLE_SUPPORT', 1, 1));
    }

    public function test_roles_for_users_groups_by_user(): void
    {
        $this->store->assignRole('user-1', 'ROLE_SUPPORT');
        $this->store->assignRole('user-1', 'ROLE_ADMIN');
        $this->store->assignRole('user-2', 'ROLE_SUPPORT');

        $result = $this->store->rolesForUsers(['user-1', 'user-2', 'user-3']);

        $this->assertSame(['ROLE_ADMIN', 'ROLE_SUPPORT'], $result['user-1']);
        $this->assertSame(['ROLE_SUPPORT'], $result['user-2']);
        $this->assertSame([], $result['user-3']);
    }

    public function test_roles_for_users_with_empty_input_returns_empty(): void
    {
        $this->assertSame([], $this->store->rolesForUsers([]));
    }
}
