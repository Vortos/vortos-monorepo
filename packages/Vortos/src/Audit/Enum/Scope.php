<?php

declare(strict_types=1);

namespace Vortos\Audit\Enum;

/**
 * The isolation boundary an audit event belongs to.
 *
 * Determines which hash chain the event is appended to and how it is queried:
 *   - Platform: cross-tenant, operator/superadmin actions. One global chain.
 *   - Tenant:   scoped to a single tenant. One chain per tenantId.
 */
enum Scope: string
{
    case Platform = 'platform';
    case Tenant   = 'tenant';

    public function requiresTenantId(): bool
    {
        return $this === self::Tenant;
    }
}
