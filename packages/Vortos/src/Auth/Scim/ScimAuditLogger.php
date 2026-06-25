<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim;

use Vortos\Auth\Audit\AuditEntry;
use Vortos\Auth\Audit\Contract\AuditStoreInterface;

final class ScimAuditLogger
{
    public function __construct(
        private readonly ?AuditStoreInterface $store = null,
    ) {}

    public function logRoleAssignment(
        string $tenantId,
        string $scimTokenId,
        string $userId,
        string $role,
        string $sourceGroupId,
        string $action = 'scim.role.assign',
    ): void {
        if ($this->store === null) {
            return;
        }

        try {
            $this->store->record(AuditEntry::create(
                userId: 'scim:' . $scimTokenId,
                action: $action,
                resourceId: $userId,
                metadata: [
                    'tenant_id'       => $tenantId,
                    'scim_token_id'   => $scimTokenId,
                    'target_user_id'  => $userId,
                    'role'            => $role,
                    'source_group_id' => $sourceGroupId,
                ],
            ));
        } catch (\Throwable) {
            // Best-effort — never fail the provisioning request for an audit write
        }
    }

    public function logRoleRemoval(
        string $tenantId,
        string $scimTokenId,
        string $userId,
        string $role,
        string $sourceGroupId,
    ): void {
        $this->logRoleAssignment($tenantId, $scimTokenId, $userId, $role, $sourceGroupId, 'scim.role.remove');
    }
}
