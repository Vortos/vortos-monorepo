<?php

declare(strict_types=1);

namespace Vortos\Auth\Scim\Tests;

use PHPUnit\Framework\TestCase;
use Vortos\Auth\Scim\Http\ScimController;
use Vortos\Auth\Scim\Middleware\ScimAuthMiddleware;
use Vortos\Auth\Scim\Token\InMemoryScimTokenStorage;
use Vortos\Auth\Scim\Token\ScimTokenService;
use Vortos\Http\Request;
use Vortos\Tenant\TenantContext;
use Symfony\Component\HttpFoundation\Response;

final class ScimAuthMiddlewareTest extends TestCase
{
    private ScimTokenService $tokenService;
    private TenantContext $tenantContext;
    private ScimAuthMiddleware $middleware;

    private const ROUTE_MAP = [
        ScimController::class . '::createUser'  => ['resource' => 'users'],
        ScimController::class . '::getUser'     => ['resource' => 'users'],
        ScimController::class . '::listUsers'   => ['resource' => 'users'],
        ScimController::class . '::replaceUser' => ['resource' => 'users'],
        ScimController::class . '::patchUser'   => ['resource' => 'users'],
        ScimController::class . '::deleteUser'  => ['resource' => 'users'],
        ScimController::class . '::createGroup'  => ['resource' => 'groups'],
        ScimController::class . '::getGroup'     => ['resource' => 'groups'],
        ScimController::class . '::listGroups'   => ['resource' => 'groups'],
    ];

    protected function setUp(): void
    {
        $this->tokenService = new ScimTokenService(new InMemoryScimTokenStorage());
        $this->tenantContext = new TenantContext();
        $this->middleware = new ScimAuthMiddleware(
            $this->tokenService,
            $this->tenantContext,
            self::ROUTE_MAP,
        );
    }

    // ── Pass-through for non-SCIM routes ──

    public function test_non_scim_route_passes_through(): void
    {
        $request = $this->request('GET', 'App\SomeController::index');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok', $response->getContent());
    }

    // ── Missing/invalid token → 401 ──

    public function test_missing_authorization_header_returns_401(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertScimError($response, 'Bearer token required.');
        $this->assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    public function test_non_bearer_auth_returns_401(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_empty_bearer_token_returns_401(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_invalid_token_returns_401(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer vsct_invalidtoken');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertScimError($response, 'Invalid, expired, or revoked SCIM token.');
    }

    public function test_expired_token_returns_401(): void
    {
        $token = $this->tokenService->issue(
            'tenant-1',
            ['scim:users:read'],
            [],
            new \DateTimeImmutable('-1 hour'),
        );

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_revoked_token_returns_401(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);
        $this->tokenService->revoke($token['record']->id);

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(401, $response->getStatusCode());
    }

    // ── Valid token → passes through ──

    public function test_valid_token_passes_through(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_valid_token_establishes_tenant_context(): void
    {
        $token = $this->tokenService->issue('tenant-alpha', ['scim:users:read']);

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);

        $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame('tenant-alpha', $this->tenantContext->tenantId());
    }

    public function test_valid_token_stamps_request_attributes(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);

        $this->middleware->handle($request, function (Request $req) use ($token) {
            $this->assertNotNull($req->attributes->get('_scim_token_record'));
            $this->assertSame('tenant-1', $req->attributes->get('_scim_tenant_id'));
            return new Response('ok');
        });
    }

    // ── Scope enforcement ──

    public function test_missing_scope_returns_403(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:groups:read']);

        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertScimError($response, 'Insufficient scope. Required: scim:users:read');
    }

    public function test_write_scope_required_for_post(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'application/scim+json');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertScimError($response, 'Insufficient scope. Required: scim:users:write');
    }

    public function test_write_scope_allows_post(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'application/scim+json');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_group_scope_required_for_group_endpoints(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('GET', ScimController::class . '::listGroups');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertScimError($response, 'Insufficient scope. Required: scim:groups:read');
    }

    public function test_delete_requires_write_scope(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('DELETE', ScimController::class . '::deleteUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertScimError($response, 'Insufficient scope. Required: scim:users:write');
    }

    // ── IP allowlist ──

    public function test_ip_not_in_allowlist_returns_403(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read'], ['10.0.0.0/8']);

        $request = $this->request('GET', ScimController::class . '::listUsers', '192.168.1.1');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertScimError($response, 'Request origin not in token IP allowlist.');
    }

    public function test_ip_in_allowlist_passes(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read'], ['10.0.0.0/8']);

        $request = $this->request('GET', ScimController::class . '::listUsers', '10.1.2.3');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_empty_allowlist_allows_any_ip(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read'], []);

        $request = $this->request('GET', ScimController::class . '::listUsers', '8.8.8.8');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_exact_ip_match_in_allowlist(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read'], ['1.2.3.4']);

        $request = $this->request('GET', ScimController::class . '::listUsers', '1.2.3.4');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_cidr_match_boundary(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read'], ['192.168.1.0/24']);

        $in = $this->request('GET', ScimController::class . '::listUsers', '192.168.1.255');
        $in->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $this->assertSame(200, $this->middleware->handle($in, fn() => new Response('ok'))->getStatusCode());

        $out = $this->request('GET', ScimController::class . '::listUsers', '192.168.2.1');
        $out->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $this->assertSame(403, $this->middleware->handle($out, fn() => new Response('ok'))->getStatusCode());
    }

    // ── Content-Type enforcement ──

    public function test_post_without_content_type_returns_415(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        // No Content-Type set
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(415, $response->getStatusCode());
    }

    public function test_post_with_scim_json_content_type_passes(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'application/scim+json');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_post_with_json_content_type_passes(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'application/json');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_put_with_wrong_content_type_returns_415(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('PUT', ScimController::class . '::replaceUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'text/plain');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(415, $response->getStatusCode());
    }

    public function test_get_does_not_require_content_type(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:read']);

        $request = $this->request('GET', ScimController::class . '::getUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_content_type_with_charset_passes(): void
    {
        $token = $this->tokenService->issue('tenant-1', ['scim:users:write']);

        $request = $this->request('POST', ScimController::class . '::createUser');
        $request->headers->set('Authorization', 'Bearer ' . $token['raw']);
        $request->headers->set('Content-Type', 'application/scim+json; charset=utf-8');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertSame(200, $response->getStatusCode());
    }

    // ── SCIM error response format ──

    public function test_error_responses_use_scim_schema(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $response = $this->middleware->handle($request, fn() => new Response('ok'));

        $body = json_decode($response->getContent(), true);
        $this->assertSame(['urn:ietf:params:scim:api:messages:2.0:Error'], $body['schemas']);
        $this->assertSame('401', $body['status']);
        $this->assertArrayHasKey('detail', $body);
    }

    // ── Tenant isolation via middleware ──

    public function test_different_tokens_establish_different_tenants(): void
    {
        $tokenA = $this->tokenService->issue('tenant-a', ['scim:users:read']);
        $tokenB = $this->tokenService->issue('tenant-b', ['scim:users:read']);

        $requestA = $this->request('GET', ScimController::class . '::listUsers');
        $requestA->headers->set('Authorization', 'Bearer ' . $tokenA['raw']);
        $this->middleware->handle($requestA, fn() => new Response('ok'));
        $this->assertSame('tenant-a', $this->tenantContext->tenantId());

        $this->tenantContext->clear();

        $requestB = $this->request('GET', ScimController::class . '::listUsers');
        $requestB->headers->set('Authorization', 'Bearer ' . $tokenB['raw']);
        $this->middleware->handle($requestB, fn() => new Response('ok'));
        $this->assertSame('tenant-b', $this->tenantContext->tenantId());
    }

    public function test_failed_auth_does_not_set_tenant(): void
    {
        $request = $this->request('GET', ScimController::class . '::listUsers');
        $request->headers->set('Authorization', 'Bearer invalid');
        $this->middleware->handle($request, fn() => new Response('ok'));

        $this->assertFalse($this->tenantContext->hasTenant());
    }

    // ── Helpers ──

    private function request(string $method, string $controller, string $clientIp = '127.0.0.1'): Request
    {
        $request = Request::create('/', $method, server: ['REMOTE_ADDR' => $clientIp]);
        $request->attributes->set('_controller', $controller);

        return $request;
    }

    private function assertScimError(Response $response, string $detail): void
    {
        $body = json_decode($response->getContent(), true);
        $this->assertSame($detail, $body['detail'] ?? null);
    }
}
