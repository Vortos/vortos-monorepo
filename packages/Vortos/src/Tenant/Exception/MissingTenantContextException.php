<?php

declare(strict_types=1);

namespace Vortos\Tenant\Exception;

/**
 * Thrown when tenant-scoped work is attempted with no tenant in context and no
 * explicit system-scope override.
 *
 * This is a fail-closed guard: a tenant-scoped repository must never fall back
 * to "see/affect everything" just because the caller forgot to establish a
 * tenant. Either a tenant is set (normal request) or the caller opts into
 * {@see \Vortos\Tenant\TenantContext::runAsSystem()} deliberately.
 */
final class MissingTenantContextException extends \RuntimeException
{
    public static function forScopedAccess(string $what): self
    {
        return new self(
            "Tenant-scoped access to {$what} was attempted with no tenant in context. " .
            'Establish a tenant (e.g. via the authenticated request, or TenantContext::runAs()), ' .
            'or use TenantContext::runAsSystem() for an explicit cross-tenant operation.'
        );
    }
}
