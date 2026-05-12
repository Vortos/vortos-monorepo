<?php

declare(strict_types=1);

namespace Vortos\Authorization\Admin;

use Doctrine\DBAL\Connection;
use Vortos\Authorization\Audit\AuthorizationAuditEntry;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Authorization\Contract\AuthorizationAuditStoreInterface;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Observability\Telemetry\TelemetryLabels;

final class UserRoleAdminService
{
    public function __construct(
        private readonly UserRoleStoreInterface $store,
        private readonly AuthorizationAuditStoreInterface $audit,
        private readonly AuthorizationVersionStoreInterface $versions,
        private readonly AuthorizationCacheInvalidatorInterface $cache,
        private readonly Connection $connection,
        private readonly ?AuthorizationTracer $tracer = null,
        private readonly ?AuthorizationAuditContextProvider $auditContext = null,
    ) {
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public function assign(
        string $actorUserId,
        string $targetUserId,
        string $role,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $span = $this->tracer?->adminMutation('authorization.admin.user_role.assign', [
            'authorization.actor_user_id_hash' => TelemetryLabels::userHash($actorUserId),
            'authorization.target_user_id_hash' => TelemetryLabels::userHash($targetUserId),
            'authorization.role' => $role,
        ]);

        try {
            $this->connection->transactional(function () use ($actorUserId, $targetUserId, $role, $reason, $metadata): void {
                $this->store->assignRole($targetUserId, $role);
                $this->versions->increment($targetUserId);
                $this->cache->invalidateUser($targetUserId);
                $this->audit->record(AuthorizationAuditEntry::create(
                    actorUserId: $actorUserId,
                    action: 'user_role.assigned',
                    targetUserId: $targetUserId,
                role: $role,
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
    public function remove(
        string $actorUserId,
        string $targetUserId,
        string $role,
        ?string $reason = null,
        array $metadata = [],
    ): void {
        $span = $this->tracer?->adminMutation('authorization.admin.user_role.remove', [
            'authorization.actor_user_id_hash' => TelemetryLabels::userHash($actorUserId),
            'authorization.target_user_id_hash' => TelemetryLabels::userHash($targetUserId),
            'authorization.role' => $role,
        ]);

        try {
            $this->connection->transactional(function () use ($actorUserId, $targetUserId, $role, $reason, $metadata): void {
                $this->store->removeRole($targetUserId, $role);
                $this->versions->increment($targetUserId);
                $this->cache->invalidateUser($targetUserId);
                $this->audit->record(AuthorizationAuditEntry::create(
                    actorUserId: $actorUserId,
                    action: 'user_role.removed',
                    targetUserId: $targetUserId,
                role: $role,
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
}
