<?php

declare(strict_types=1);

namespace Vortos\Audit\Http;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Audit\Storage\Dbal\Postgres\AuditTenantGuc;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;

/**
 * Activates the audit_events row-level-security tenant isolation for the current request.
 *
 * Sets the Postgres `app.current_tenant` GUC (that the RLS policy keys on) to the request's
 * tenant — or clears it for platform/system requests — so a tenant session is DB-confined to
 * its own audit rows, a backstop behind the query-layer scoping. The value is (re)written on
 * EVERY request, so a pooled/worker-mode connection never inherits a previous request's scope.
 *
 * Registered only when `->rowLevelSecurity(true)` is configured on Postgres. Runs after auth
 * resolves the tenant and before the controller queries. No-op off Postgres.
 */
#[AsMiddleware(order: MiddlewareOrder::OWNERSHIP)]
final class AuditTenantRlsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditTenantGuc $guc,
        private readonly TenantContext  $tenantContext,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId !== null && $tenantId !== '') {
            $this->guc->scopeToTenant($tenantId);
        } else {
            // Platform/system request — leave the trail unrestricted (policy is permissive when unset).
            $this->guc->clear();
        }

        return $next($request);
    }
}
