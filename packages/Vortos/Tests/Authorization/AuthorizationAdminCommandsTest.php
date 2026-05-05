<?php

declare(strict_types=1);

namespace Tests\Authorization;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Authorization\Admin\RolePermissionAdminService;
use Vortos\Authorization\Admin\UserRoleAdminService;
use Vortos\Authorization\Command\AuthAssignUserRoleCommand;
use Vortos\Authorization\Command\AuthGrantRolePermissionCommand;
use Vortos\Authorization\Command\AuthRemoveUserRoleCommand;
use Vortos\Authorization\Command\AuthRevokeRolePermissionCommand;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Storage\DbalAuthorizationAuditStore;
use Vortos\Authorization\Storage\DbalRolePermissionStore;
use Vortos\Authorization\Storage\DbalUserRoleStore;

final class AuthorizationAdminCommandsTest extends TestCase
{
    public function test_assign_and_remove_user_role_commands_use_admin_service_path(): void
    {
        $connection = $this->connection();
        $versions = new class implements AuthorizationVersionStoreInterface {
            public int $increments = 0;
            public function versionForUser(string $userId): int { return $this->increments; }
            public function increment(string $userId): int { return ++$this->increments; }
        };
        $invalidated = [];
        $service = new UserRoleAdminService(
            new DbalUserRoleStore($connection),
            new DbalAuthorizationAuditStore($connection),
            $versions,
            new class($invalidated) implements AuthorizationCacheInvalidatorInterface {
                public function __construct(private array &$invalidated) {}
                public function invalidateUser(string $userId): void { $this->invalidated[] = $userId; }
            },
            $connection,
        );

        $assign = new CommandTester(new AuthAssignUserRoleCommand($service));
        $this->assertSame(0, $assign->execute([
            'user' => 'user-1',
            'role' => 'ROLE_SUPPORT',
            '--actor' => 'admin-1',
            '--reason' => 'support promotion',
        ]));

        $this->assertSame(['ROLE_SUPPORT'], (new DbalUserRoleStore($connection))->rolesForUser('user-1'));

        $remove = new CommandTester(new AuthRemoveUserRoleCommand($service));
        $this->assertSame(0, $remove->execute([
            'user' => 'user-1',
            'role' => 'ROLE_SUPPORT',
            '--actor' => 'admin-1',
            '--reason' => 'support rotation ended',
        ]));

        $this->assertSame([], (new DbalUserRoleStore($connection))->rolesForUser('user-1'));
        $this->assertSame(2, $versions->increments);
        $this->assertSame(['user-1', 'user-1'], $invalidated);
        $this->assertSame(['user_role.assigned', 'user_role.removed'], $this->auditActions($connection));
    }

    public function test_grant_and_revoke_role_permission_commands_validate_and_audit(): void
    {
        $connection = $this->connection();
        $service = new RolePermissionAdminService(
            new DbalRolePermissionStore($connection),
            new DbalAuthorizationAuditStore($connection),
            $this->permissionRegistry(),
            $connection,
        );

        $grant = new CommandTester(new AuthGrantRolePermissionCommand($service));
        $this->assertSame(0, $grant->execute([
            'role' => 'ROLE_SUPPORT',
            'permission' => 'orders.refund.any',
            '--actor' => 'admin-1',
            '--metadata' => ['ticket=SUP-42'],
        ]));

        $this->assertSame(
            ['orders.refund.any'],
            (new DbalRolePermissionStore($connection))->permissionsForRole('ROLE_SUPPORT'),
        );

        $revoke = new CommandTester(new AuthRevokeRolePermissionCommand($service));
        $this->assertSame(0, $revoke->execute([
            'role' => 'ROLE_SUPPORT',
            'permission' => 'orders.refund.any',
            '--actor' => 'admin-1',
        ]));

        $this->assertSame([], (new DbalRolePermissionStore($connection))->permissionsForRole('ROLE_SUPPORT'));
        $this->assertSame(['role_permission.granted', 'role_permission.revoked'], $this->auditActions($connection));
    }

    public function test_role_permission_grant_command_rejects_unregistered_permission(): void
    {
        $connection = $this->connection();
        $command = new AuthGrantRolePermissionCommand(new RolePermissionAdminService(
            new DbalRolePermissionStore($connection),
            new DbalAuthorizationAuditStore($connection),
            $this->permissionRegistry(),
            $connection,
        ));
        $tester = new CommandTester($command);

        $this->assertSame(2, $tester->execute([
            'role' => 'ROLE_SUPPORT',
            'permission' => 'orders.typo.any',
            '--actor' => 'admin-1',
        ]));
        $this->assertStringContainsString('Permission "orders.typo.any" is not registered.', $tester->getDisplay());
        $this->assertSame([], $this->auditActions($connection));
    }

    private function connection(): \Doctrine\DBAL\Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE role_permissions (role VARCHAR(150) NOT NULL, permission VARCHAR(190) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (role, permission))');
        $connection->executeStatement('CREATE TABLE user_roles (user_id VARCHAR(190) NOT NULL, role VARCHAR(150) NOT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (user_id, role))');
        $connection->executeStatement('CREATE TABLE authorization_audit_log (id VARCHAR(64) NOT NULL, actor_user_id VARCHAR(190) NOT NULL, action VARCHAR(190) NOT NULL, target_user_id VARCHAR(190) DEFAULT NULL, role VARCHAR(150) DEFAULT NULL, permission VARCHAR(190) DEFAULT NULL, reason TEXT DEFAULT NULL, metadata TEXT NOT NULL DEFAULT \'{}\', request_id VARCHAR(190) DEFAULT NULL, correlation_id VARCHAR(190) DEFAULT NULL, ip_address VARCHAR(64) DEFAULT NULL, user_agent TEXT DEFAULT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id))');

        return $connection;
    }

    /**
     * @return string[]
     */
    private function auditActions(\Doctrine\DBAL\Connection $connection): array
    {
        $actions = array_map(
            'strval',
            $connection->executeQuery('SELECT action FROM authorization_audit_log')->fetchFirstColumn(),
        );
        sort($actions);

        return $actions;
    }

    private function permissionRegistry(): PermissionRegistry
    {
        return new PermissionRegistry([
            'orders.refund.any' => [
                'permission' => 'orders.refund.any',
                'resource' => 'orders',
                'action' => 'refund',
                'scope' => 'any',
                'label' => 'Refund any order',
                'description' => null,
                'dangerous' => false,
                'bypassable' => false,
                'group' => 'Orders',
                'catalogClass' => self::class,
            ],
        ]);
    }
}
