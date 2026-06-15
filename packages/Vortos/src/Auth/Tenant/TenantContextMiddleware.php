<?php

declare(strict_types=1);

namespace Vortos\Auth\Tenant;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

/**
 * Populates the ambient {@see TenantContext} from the authenticated identity —
 * Layer 1 of tenant isolation on the HTTP path.
 *
 * Runs just after authentication (order 680: below AUTH=700, above 2FA and
 * authorization) so every component downstream — authorization, handlers, the
 * persistence layer — sees the resolved tenant. The tenant is read from a claim
 * the app puts in the user's token (default claim name: "tenant").
 *
 * Anonymous requests, or authenticated users with no tenant claim, leave the
 * context empty — tenant-scoped repositories then fail closed rather than leak.
 *
 * After resolving the tenant it binds the database session variable (via the
 * GUC binder, when a persistence adapter provides one) so the ORM tenant filter
 * and RLS see the tenant on reads too. The binder is called on EVERY request —
 * including anonymous ones — so a reused worker connection cannot retain a
 * previous request's tenant.
 */
#[AsMiddleware(order: 680)]
final class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly string $tenantClaim = 'tenant',
        private readonly ?TenantGucBinderInterface $gucBinder = null,
    ) {}

    public function handle(Request $request, \Closure $next): Response
    {
        $identity = $this->currentUser->get();

        if ($identity->isAuthenticated()) {
            $tenant = $identity->getAttribute($this->tenantClaim);

            if (is_string($tenant) && $tenant !== '') {
                $this->tenantContext->set($tenant);
            }
        }

        $this->gucBinder?->bindSession();

        return $next($request);
    }
}
