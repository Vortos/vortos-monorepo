<?php

declare(strict_types=1);

namespace Tests\Authorization;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Authorization\Admin\RolePermissionAdminService;
use Vortos\Authorization\Admin\UserRoleAdminService;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Storage\DbalAuthorizationAuditStore;
use Vortos\Authorization\Storage\DbalRolePermissionStore;
use Vortos\Authorization\Storage\DbalUserRoleStore;

final class AuthorizationAdminServicesTest extends TestCase
{
    public function test_role_permission_changes_are_audited(): void
    {
        $connection = $this->connection();
        $audit = new DbalAuthorizationAuditStore($connection);
        $service = new RolePermissionAdminService(
            new DbalRolePermissionStore($connection),
            $audit,
            $this->permissionRegistry(),
            $connection,
        );

        $service->grant('admin-1', 'ROLE_SUPPORT', 'orders.refund.any', 'ticket approved');
        $service->revoke('admin-1', 'ROLE_SUPPORT', 'orders.refund.any');

        $this->assertSame([], (new DbalRolePermissionStore($connection))->permissionsForRole('ROLE_SUPPORT'));
        $this->assertSame(
            ['role_permission.granted', 'role_permission.revoked'],
            $this->auditActions($connection),
        );
    }

    public function test_role_permission_changes_reject_unknown_permissions(): void
    {
        $connection = $this->connection();
        $service = new RolePermissionAdminService(
            new DbalRolePermissionStore($connection),
            new DbalAuthorizationAuditStore($connection),
            $this->permissionRegistry(),
            $connection,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Permission "orders.typo.any" is not registered.');

        $service->grant('admin-1', 'ROLE_SUPPORT', 'orders.typo.any');
    }

    public function test_dangerous_permission_changes_require_reason(): void
    {
        $connection = $this->connection();
        $service = new RolePermissionAdminService(
            new DbalRolePermissionStore($connection),
            new DbalAuthorizationAuditStore($connection),
            $this->permissionRegistry(),
            $connection,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A reason is required when changing dangerous permission "orders.delete.any".');

        $service->grant('admin-1', 'ROLE_SUPPORT', 'orders.delete.any');
    }

    public function test_user_role_changes_increment_version_invalidate_cache_and_audit(): void
    {
        $connection = $this->connection();
        $audit = new DbalAuthorizationAuditStore($connection);
        $invalidated = [];
        $cache = new class($invalidated) implements AuthorizationCacheInvalidatorInterface {
            public function __construct(private array &$invalidated) {}
            public function invalidateUser(string $userId): void { $this->invalidated[] = $userId; }
        };
        $versions = new class implements AuthorizationVersionStoreInterface {
            public int $increments = 0;
            public function versionForUser(string $userId): int { return $this->increments; }
            public function increment(string $userId): int { return ++$this->increments; }
        };
        $service = new UserRoleAdminService(
            new DbalUserRoleStore($connection),
            $audit,
            $versions,
            $cache,
            $connection,
        );

        $service->assign('admin-1', 'user-1', 'ROLE_SUPPORT');
        $service->remove('admin-1', 'user-1', 'ROLE_SUPPORT');

        $this->assertSame([], (new DbalUserRoleStore($connection))->rolesForUser('user-1'));
        $this->assertSame(2, $versions->increments);
        $this->assertSame(['user-1', 'user-1'], $invalidated);
        $this->assertSame(
            ['user_role.assigned', 'user_role.removed'],
            $this->auditActions($connection),
        );
    }

    public function test_admin_service_audit_includes_request_and_correlation_context(): void
    {
        $connection = $this->connection();
        $requestStack = new RequestStack();
        $request = Request::create('/admin/roles', 'POST', server: [
            'REMOTE_ADDR' => '203.0.113.10',
            'HTTP_USER_AGENT' => 'VortosTest/1.0',
            'HTTP_X_REQUEST_ID' => 'req-123',
            'HTTP_X_CORRELATION_ID' => 'corr-header',
        ]);
        $request->attributes->set('_route', 'admin.roles.assign');
        $requestStack->push($request);

        $service = new UserRoleAdminService(
            new DbalUserRoleStore($connection),
            new DbalAuthorizationAuditStore($connection),
            new class implements AuthorizationVersionStoreInterface {
                public function versionForUser(string $userId): int { return 0; }
                public function increment(string $userId): int { return 1; }
            },
            new class implements AuthorizationCacheInvalidatorInterface {
                public function invalidateUser(string $userId): void {}
            },
            $connection,
            auditContext: new AuthorizationAuditContextProvider($requestStack),
        );

        $service->assign('admin-1', 'user-1', 'ROLE_SUPPORT');

        $row = $connection->executeQuery('SELECT request_id, correlation_id, ip_address, user_agent, metadata FROM authorization_audit_log')->fetchAssociative();

        $this->assertSame('req-123', $row['request_id']);
        $this->assertSame('corr-header', $row['correlation_id']);
        $this->assertSame('203.0.113.10', $row['ip_address']);
        $this->assertSame('VortosTest/1.0', $row['user_agent']);
        $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('admin.roles.assign', $metadata['route']);
        $this->assertSame('/admin/roles', $metadata['path']);
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
            'orders.delete.any' => [
                'permission' => 'orders.delete.any',
                'resource' => 'orders',
                'action' => 'delete',
                'scope' => 'any',
                'label' => 'Delete any order',
                'description' => null,
                'dangerous' => true,
                'bypassable' => false,
                'group' => 'Orders',
                'catalogClass' => self::class,
            ],
        ]);
    }
}
