<?php

declare(strict_types=1);

namespace Vortos\Auth\Tenant;

use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Http\Attribute\AsMiddleware;
use Vortos\Http\Contract\MiddlewareInterface;
use Vortos\Http\MiddlewareOrder;
use Vortos\Http\Request;
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
 */
#[AsMiddleware(order: 680)]
final class TenantContextMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CurrentUserProvider $currentUser,
        private readonly TenantContext $tenantContext,
        private readonly string $tenantClaim = 'tenant',
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

        return $next($request);
    }
}
