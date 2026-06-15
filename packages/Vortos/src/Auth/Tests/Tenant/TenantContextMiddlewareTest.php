<?php

declare(strict_types=1);

namespace Vortos\Auth\Tests\Tenant;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Auth\Identity\UserIdentity;
use Vortos\Auth\Tenant\TenantContextMiddleware;
use Vortos\Cache\Adapter\ArrayAdapter;
use Vortos\Http\Request;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

final class TenantContextMiddlewareTest extends TestCase
{
    public function test_binds_the_session_guc_every_request(): void
    {
        $binder = new class implements TenantGucBinderInterface {
            public int $sessionBinds = 0;
            public function bindSession(): void { $this->sessionBinds++; }
            public function bindLocal(): void {}
        };

        $adapter = new ArrayAdapter();
        $adapter->set('auth:identity', new UserIdentity('user-1', [], ['tenant' => 'acme']));

        $middleware = new TenantContextMiddleware(
            new CurrentUserProvider($adapter),
            new TenantContext(),
            'tenant',
            $binder,
        );

        $middleware->handle(new Request(), fn(Request $r) => new Response('ok'));

        $this->assertSame(1, $binder->sessionBinds);
    }


    public function test_sets_tenant_from_authenticated_identity_claim(): void
    {
        $tenant = $this->dispatch(new UserIdentity('user-1', ['ROLE_USER'], ['tenant' => 'acme']));

        $this->assertSame('acme', $tenant->tenantId());
    }

    public function test_custom_claim_name(): void
    {
        $tenant = $this->dispatch(
            new UserIdentity('user-1', [], ['org_id' => 'globex']),
            claim: 'org_id',
        );

        $this->assertSame('globex', $tenant->tenantId());
    }

    public function test_anonymous_request_leaves_context_empty(): void
    {
        $tenant = $this->dispatch(identity: null);

        $this->assertFalse($tenant->hasTenant());
    }

    public function test_authenticated_without_claim_leaves_context_empty(): void
    {
        $tenant = $this->dispatch(new UserIdentity('user-1', ['ROLE_USER']));

        $this->assertFalse($tenant->hasTenant());
    }

    private function dispatch(?UserIdentity $identity, string $claim = 'tenant'): TenantContext
    {
        $adapter = new ArrayAdapter();
        if ($identity !== null) {
            $adapter->set('auth:identity', $identity);
        }

        $tenantContext = new TenantContext();
        $middleware = new TenantContextMiddleware(
            new CurrentUserProvider($adapter),
            $tenantContext,
            $claim,
        );

        $response = $middleware->handle(new Request(), fn(Request $r) => new Response('ok'));
        $this->assertSame('ok', $response->getContent());

        return $tenantContext;
    }
}
