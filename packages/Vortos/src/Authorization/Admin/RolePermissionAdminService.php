<?php

declare(strict_types=1);

namespace Vortos\Authorization\Admin;

use Doctrine\DBAL\Connection;
use InvalidArgumentException;
use Vortos\Authorization\Audit\AuthorizationAuditEntry;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Authorization\Contract\AuthorizationAuditStoreInterface;
use Vortos\Authorization\Contract\PermissionRegistryInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Tracing\AuthorizationTracer;

final class RolePermissionAdminService
{
    public function __construct(
        private readonly RolePermissionStoreInterface $store,
        private readonly AuthorizationAuditStoreInterface $audit,
        private readonly PermissionRegistryInterface $registry,
        private readonly Connection $connection,
        private readonly ?AuthorizationTracer $tracer = null,
        private readonly ?AuthorizationAuditContextProvider $auditContext = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function grant(
        string $actorUserId,
        string $role,
        string $permission,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->assertKnownPermission($permission);
        $this->assertReasonWhenDangerous($permission, $reason);

        $span = $this->tracer?->adminMutation('authorization.admin.role_permission.grant', [
            'authorization.actor_user_id_hash' => hash('sha256', $actorUserId),
            'authorization.role' => $role,
            'authorization.permission' => $permission,
        ]);

        try {
            $this->connection->transactional(function () use ($actorUserId, $role, $permission, $reason, $metadata): void {
                $this->store->grant($role, $permission);
                $this->audit->record(AuthorizationAuditEntry::create(
                    actorUserId: $actorUserId,
                    action: 'role_permission.granted',
                    role: $role,
                permission: $permission,
                reason: $reason,
                metadata: $metadata,
                context: $this->auditContext?->current(),
            ));
        });

            $span?->setStatus('ok');
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function revoke(
        string $actorUserId,
        string $role,
        string $permission,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $this->assertKnownPermission($permission);
        $this->assertReasonWhenDangerous($permission, $reason);

        $span = $this->tracer?->adminMutation('authorization.admin.role_permission.revoke', [
            'authorization.actor_user_id_hash' => hash('sha256', $actorUserId),
            'authorization.role' => $role,
            'authorization.permission' => $permission,
        ]);

        try {
            $this->connection->transactional(function () use ($actorUserId, $role, $permission, $reason, $metadata): void {
                $this->store->revoke($role, $permission);
                $this->audit->record(AuthorizationAuditEntry::create(
                    actorUserId: $actorUserId,
                    action: 'role_permission.revoked',
                    role: $role,
                permission: $permission,
                reason: $reason,
                metadata: $metadata,
                context: $this->auditContext?->current(),
            ));
        });

            $span?->setStatus('ok');
        } catch (\Throwable $e) {
            $span?->recordException($e);
            $span?->setStatus('error');
            throw $e;
        } finally {
            $span?->end();
        }
    }

    private function assertKnownPermission(string $permission): void
    {
        if (!$this->registry->exists($permission)) {
            throw new InvalidArgumentException(sprintf('Permission "%s" is not registered.', $permission));
        }
    }

    private function assertReasonWhenDangerous(string $permission, ?string $reason): void
    {
        $metadata = $this->registry->metadata($permission);

        if ($metadata?->dangerous && trim((string) $reason) === '') {
            throw new InvalidArgumentException(sprintf(
                'A reason is required when changing dangerous permission "%s".',
                $permission,
            ));
        }
    }
}
