<?php
declare(strict_types=1);

namespace Vortos\Authorization\Scope;

use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;

/**
 * Fluent manager for scoped permissions.
 *
 * Usage in command handlers:
 *   $this->authorization->forScope('org', $orgId)->grant($userId, 'documents.edit');
 *   $this->authorization->forScope('org', $orgId)->revoke($userId, 'documents.edit');
 *   $this->authorization->forScope('org', $orgId)->has($userId, 'documents.edit');
 */
final class ScopedAuthorizationManager
{
    public function __construct(
        private ScopedPermissionStoreInterface $store,
    ) {}

    public function forScope(string $scopeName, string $scopeId): ScopedPermissionContext
    {
        return new ScopedPermissionContext($this->store, $scopeName, $scopeId);
    }
}
